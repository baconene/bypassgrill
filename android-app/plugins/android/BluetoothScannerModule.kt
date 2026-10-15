package com.bypassgrill.pos.bluetooth

import android.annotation.SuppressLint
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.location.LocationManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import com.facebook.react.bridge.Arguments
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.facebook.react.bridge.WritableMap

/**
 * Classic Bluetooth discovery and pairing for receipt printers.
 *
 * The thermal printer library only lists devices that are already paired, so a
 * printer that was never paired in Android settings could not be found from the
 * app. This module scans for nearby Classic devices (ESC/POS printers speak SPP,
 * not BLE) and can pair with one, after which the library connects to it.
 */
class BluetoothScannerModule(private val ctx: ReactApplicationContext) : ReactContextBaseJavaModule(ctx) {
    private data class Found(val name: String, val address: String, val bonded: Boolean, val rssi: Int?, val majorClass: Int?, val type: Int?)

    private val handler = Handler(Looper.getMainLooper())
    private val found = LinkedHashMap<String, Found>()
    private var scanReceiver: BroadcastReceiver? = null
    private var scanPromise: Promise? = null
    private var scanTimeout: Runnable? = null
    private var bondReceiver: BroadcastReceiver? = null
    private var bondPromise: Promise? = null
    private var bondTimeout: Runnable? = null

    override fun getName(): String = "BypassBluetoothScanner"

    private val adapter: BluetoothAdapter?
        get() = (ctx.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter

    @ReactMethod
    fun getAdapterState(promise: Promise) {
        val a = adapter
        val lm = ctx.getSystemService(Context.LOCATION_SERVICE) as? LocationManager
        promise.resolve(Arguments.createMap().apply {
            putBoolean("supported", a != null)
            putBoolean("enabled", runCatching { a?.isEnabled == true }.getOrDefault(false))
            // Before Android 12, discovery returns nothing while Location is off
            putBoolean("locationRequired", Build.VERSION.SDK_INT < Build.VERSION_CODES.S)
            putBoolean("locationEnabled", lm != null && locationEnabled(lm))
        })
    }

    /** Shows the system "Allow app to turn on Bluetooth?" dialog */
    @ReactMethod
    fun requestEnable(promise: Promise) {
        try {
            val intent = Intent(BluetoothAdapter.ACTION_REQUEST_ENABLE)
            val activity = ctx.getCurrentActivity()
            if (activity != null) activity.startActivity(intent)
            else ctx.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            promise.resolve(true)
        } catch (e: SecurityException) {
            promise.reject("permission_denied", "Bluetooth permission not granted", e)
        } catch (e: Exception) {
            promise.reject("error", e.message ?: "Could not open Bluetooth settings", e)
        }
    }

    /**
     * Discovers nearby Classic Bluetooth devices for up to [durationMs].
     * Paired devices are always included, since a connected printer stops
     * advertising and would otherwise never show up.
     */
    @SuppressLint("MissingPermission")
    @ReactMethod
    fun scan(durationMs: Double, promise: Promise) {
        handler.post {
            val a = adapter
            if (a == null) {
                promise.resolve(scanResult(emptyList(), "unavailable"))
                return@post
            }
            if (!a.isEnabled) {
                promise.resolve(scanResult(emptyList(), "bluetooth_off"))
                return@post
            }
            finishScan(null)
            found.clear()
            try {
                a.bondedDevices?.forEach { d -> remember(d, null, true) }
            } catch (e: SecurityException) {
                promise.resolve(scanResult(emptyList(), "permission"))
                return@post
            }

            runCatching { if (a.isDiscovering) a.cancelDiscovery() }
            val receiver = object : BroadcastReceiver() {
                // A cancelled earlier discovery also broadcasts FINISHED; only honour ours
                var started = false
                override fun onReceive(context: Context, intent: Intent) {
                    when (intent.action) {
                        BluetoothAdapter.ACTION_DISCOVERY_STARTED -> started = true
                        BluetoothDevice.ACTION_FOUND -> {
                            val device = deviceFrom(intent) ?: return
                            val rssi = intent.getShortExtra(BluetoothDevice.EXTRA_RSSI, Short.MIN_VALUE).toInt()
                            runCatching { remember(device, if (rssi == Short.MIN_VALUE.toInt()) null else rssi, null) }
                        }
                        BluetoothAdapter.ACTION_DISCOVERY_FINISHED -> if (started) finishScan(null)
                    }
                }
            }
            val filter = IntentFilter().apply {
                addAction(BluetoothDevice.ACTION_FOUND)
                addAction(BluetoothAdapter.ACTION_DISCOVERY_STARTED)
                addAction(BluetoothAdapter.ACTION_DISCOVERY_FINISHED)
            }
            register(receiver, filter)
            scanReceiver = receiver
            scanPromise = promise

            val started = try {
                a.startDiscovery()
            } catch (e: SecurityException) {
                finishScan("permission")
                return@post
            }
            if (!started) {
                finishScan("scan_failed")
                return@post
            }
            val timeout = Runnable { finishScan(null) }
            scanTimeout = timeout
            handler.postDelayed(timeout, durationMs.toLong().coerceIn(3000L, 30000L))
        }
    }

    @ReactMethod
    fun stopScan(promise: Promise) {
        handler.post {
            finishScan(null)
            promise.resolve(true)
        }
    }

    /** Pairs (bonds) with a device, showing the system PIN prompt if the printer needs one */
    @SuppressLint("MissingPermission")
    @ReactMethod
    fun pair(address: String, promise: Promise) {
        handler.post {
            val a = adapter
            if (a == null || !a.isEnabled) {
                promise.reject("bluetooth_off", "Bluetooth is off")
                return@post
            }
            try {
                finishScan(null)
                val device = a.getRemoteDevice(address)
                if (device.bondState == BluetoothDevice.BOND_BONDED) {
                    promise.resolve(true)
                    return@post
                }
                finishBond(false, "Pairing was replaced by a new request")
                val receiver = object : BroadcastReceiver() {
                    override fun onReceive(context: Context, intent: Intent) {
                        val d = deviceFrom(intent) ?: return
                        if (!d.address.equals(address, ignoreCase = true)) return
                        when (intent.getIntExtra(BluetoothDevice.EXTRA_BOND_STATE, BluetoothDevice.ERROR)) {
                            BluetoothDevice.BOND_BONDED -> finishBond(true, null)
                            BluetoothDevice.BOND_NONE -> finishBond(false, "Pairing was cancelled or rejected by the printer. Common printer PINs are 0000 or 1234.")
                        }
                    }
                }
                register(receiver, IntentFilter(BluetoothDevice.ACTION_BOND_STATE_CHANGED))
                bondReceiver = receiver
                bondPromise = promise
                if (!device.createBond()) {
                    finishBond(false, "Android refused to start pairing with this device")
                    return@post
                }
                val timeout = Runnable { finishBond(false, "Pairing timed out") }
                bondTimeout = timeout
                handler.postDelayed(timeout, 60000L)
            } catch (e: SecurityException) {
                if (bondPromise === promise) finishBond(false, "Bluetooth permission not granted")
                else promise.reject("permission_denied", "Bluetooth permission not granted", e)
            } catch (e: Exception) {
                if (bondPromise === promise) finishBond(false, e.message ?: "Could not pair")
                else promise.reject("error", e.message ?: "Could not pair", e)
            }
        }
    }

    @SuppressLint("MissingPermission")
    private fun remember(device: BluetoothDevice, rssi: Int?, bonded: Boolean?) {
        val address = device.address ?: return
        val previous = found[address]
        val name = device.name ?: previous?.name?.takeIf { it != "Unknown device" } ?: "Unknown device"
        found[address] = Found(
            name = name,
            address = address,
            bonded = bonded ?: (device.bondState == BluetoothDevice.BOND_BONDED),
            rssi = rssi ?: previous?.rssi,
            majorClass = runCatching { device.bluetoothClass?.majorDeviceClass }.getOrNull(),
            type = runCatching { device.type }.getOrNull(),
        )
    }

    /** Bluetooth broadcasts come from the Bluetooth stack's own process, so the receiver must be exported */
    private fun register(receiver: BroadcastReceiver, filter: IntentFilter) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) ctx.registerReceiver(receiver, filter, Context.RECEIVER_EXPORTED)
        else ctx.registerReceiver(receiver, filter)
    }

    private fun locationEnabled(lm: LocationManager): Boolean =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) lm.isLocationEnabled
        else runCatching { lm.isProviderEnabled(LocationManager.GPS_PROVIDER) || lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER) }.getOrDefault(false)

    private fun deviceFrom(intent: Intent): BluetoothDevice? =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            intent.getParcelableExtra(BluetoothDevice.EXTRA_DEVICE, BluetoothDevice::class.java)
        } else {
            @Suppress("DEPRECATION")
            intent.getParcelableExtra(BluetoothDevice.EXTRA_DEVICE)
        }

    @SuppressLint("MissingPermission")
    private fun finishScan(error: String?) {
        scanTimeout?.let { handler.removeCallbacks(it) }
        scanTimeout = null
        scanReceiver?.let { runCatching { ctx.unregisterReceiver(it) } }
        scanReceiver = null
        runCatching { adapter?.cancelDiscovery() }
        val promise = scanPromise ?: return
        scanPromise = null
        promise.resolve(scanResult(found.values.toList(), error))
    }

    private fun finishBond(success: Boolean, error: String?) {
        bondTimeout?.let { handler.removeCallbacks(it) }
        bondTimeout = null
        bondReceiver?.let { runCatching { ctx.unregisterReceiver(it) } }
        bondReceiver = null
        val promise = bondPromise ?: return
        bondPromise = null
        if (success) promise.resolve(true) else promise.reject("pair_failed", error ?: "Pairing failed")
    }

    private fun scanResult(devices: List<Found>, error: String?): WritableMap = Arguments.createMap().apply {
        val list = Arguments.createArray()
        devices.forEach { d ->
            list.pushMap(Arguments.createMap().apply {
                putString("name", d.name)
                putString("address", d.address)
                putBoolean("bonded", d.bonded)
                if (d.rssi != null) putInt("rssi", d.rssi) else putNull("rssi")
                putInt("majorClass", d.majorClass ?: 0)
                putString("transport", when (d.type) {
                    BluetoothDevice.DEVICE_TYPE_CLASSIC -> "classic"
                    BluetoothDevice.DEVICE_TYPE_LE -> "ble"
                    BluetoothDevice.DEVICE_TYPE_DUAL -> "dual"
                    else -> "unknown"
                })
            })
        }
        putArray("devices", list)
        putString("error", error)
    }

    override fun invalidate() {
        handler.post {
            finishScan(null)
            finishBond(false, "App closed")
        }
        super.invalidate()
    }
}
