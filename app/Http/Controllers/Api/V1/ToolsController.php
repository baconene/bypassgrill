<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lightweight, READ-ONLY in-app SQL console. Admin only. Only SELECT-style
 * statements run; everything is executed inside a transaction that is always
 * rolled back, so the console can never modify data.
 */
class ToolsController extends Controller
{
    private const ROW_CAP = 500;

    /** List all tables in the current database with approximate row counts. */
    public function tables(): JsonResponse
    {
        $this->adminOnly();

        $rows = DB::select(
            "SELECT table_name AS name, table_rows AS approx_rows
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             ORDER BY table_name"
        );

        return response()->json(array_map(fn ($r) => [
            'name'        => $r->name,
            'approx_rows' => (int) ($r->approx_rows ?? 0),
        ], $rows));
    }

    /** Columns for a single table (for the schema browser). */
    public function columns(string $table): JsonResponse
    {
        $this->adminOnly();

        $cols = DB::select(
            "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_key AS keytype
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?
             ORDER BY ordinal_position",
            [$table]
        );

        return response()->json(array_map(fn ($c) => [
            'name'     => $c->name,
            'type'     => $c->type,
            'nullable' => $c->nullable === 'YES',
            'key'      => $c->keytype,
        ], $cols));
    }

    /** Execute a read-only query and return columns + rows. */
    public function query(Request $request): JsonResponse
    {
        $this->adminOnly();

        $data = $request->validate(['sql' => 'required|string|max:10000']);
        $sql  = $this->sanitize($data['sql']);

        $error = $this->guard($sql);
        if ($error) {
            return response()->json(['ok' => false, 'message' => $error], 422);
        }

        $start = microtime(true);
        try {
            // Defense in depth: run inside a transaction and always roll back,
            // so even a query that slips past the guard can't persist changes.
            DB::beginTransaction();
            $rows = DB::select($sql);
            DB::rollBack();

            $truncated = count($rows) > self::ROW_CAP;
            $rows = array_slice($rows, 0, self::ROW_CAP);

            $columns = $rows ? array_keys((array) $rows[0]) : [];
            $data = array_map(fn ($r) => (array) $r, $rows);

            return response()->json([
                'ok'         => true,
                'columns'    => $columns,
                'rows'       => $data,
                'row_count'  => count($data),
                'truncated'  => $truncated,
                'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function sanitize(string $sql): string
    {
        $sql = trim($sql);
        // strip a single trailing semicolon
        return rtrim($sql, "; \t\n\r");
    }

    /** Returns an error string if the query is not a safe read-only statement. */
    private function guard(string $sql): ?string
    {
        if ($sql === '') {
            return 'Empty query.';
        }

        // Block multiple statements (a semicolon followed by more SQL)
        if (preg_match('/;\s*\S/', $sql)) {
            return 'Only a single statement is allowed.';
        }

        // Must start with a read-only verb
        if (! preg_match('/^\s*(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE|DESC)\b/i', $sql)) {
            return 'Only SELECT / SHOW / EXPLAIN / DESCRIBE queries are allowed (read-only console).';
        }

        // Block dangerous keywords anywhere (whole-word)
        $blocked = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE',
            'REPLACE', 'GRANT', 'REVOKE', 'RENAME', 'LOCK', 'UNLOCK', 'CALL',
            'MERGE', 'HANDLER', 'PREPARE', 'EXECUTE',
        ];
        if (preg_match('/\b(' . implode('|', $blocked) . ')\b/i', $sql)) {
            return 'That query contains a disallowed keyword. This console is read-only.';
        }

        // Block file/system access vectors
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql) || stripos($sql, 'LOAD_FILE') !== false) {
            return 'File access is not allowed.';
        }

        return null;
    }

    private function adminOnly(): void
    {
        if (! auth()->user()?->hasAnyRole('admin')) {
            abort(403, 'Admin only');
        }
    }
}
