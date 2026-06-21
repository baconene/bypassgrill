<?php

namespace Database\Seeders;

use App\Models\PageSection;
use Illuminate\Database\Seeder;

class PageSectionSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            [
                'key'           => 'hero',
                'label'         => 'Hero — Main Banner',
                'position'      => 'before_products',
                'display_order' => 10,
                'content'       => <<<'HTML'
<section class="relative min-h-screen flex items-center justify-center pt-28 pb-16 px-6 overflow-hidden">
  <div class="absolute inset-0 pointer-events-none">
    <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[900px] h-[500px] rounded-full opacity-30" style="background:radial-gradient(ellipse,#ff6600 0%,#c2410c 30%,transparent 70%);filter:blur(60px)"></div>
    <div class="absolute top-1/3 left-1/4 w-80 h-80 rounded-full opacity-10" style="background:radial-gradient(circle,#fbbf24 0%,transparent 70%);filter:blur(40px)"></div>
  </div>
  <div class="relative z-10 max-w-5xl mx-auto text-center">
    <div class="inline-flex items-center gap-2 rounded-full border border-orange-700 bg-orange-950/60 px-4 py-1.5 text-xs font-semibold uppercase tracking-widest text-orange-400 mb-8">
      <span class="h-1.5 w-1.5 rounded-full bg-orange-500 animate-pulse inline-block"></span>
      Now Open · Calamba, Laguna
    </div>
    <h1 class="text-6xl sm:text-7xl md:text-8xl font-black leading-none tracking-tight mb-6">
      <span class="block text-white">THE</span>
      <span class="block" style="background:linear-gradient(90deg,#f97316,#fbbf24,#f97316);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">BOYS</span>
      <span class="block text-white text-4xl sm:text-5xl font-light tracking-widest mt-2">GRILLED BURGERS</span>
    </h1>
    <p class="max-w-xl mx-auto text-lg text-gray-300 leading-relaxed mb-10">
      Juicy, flame-grilled burgers made fresh every day.<br>
      <span class="text-orange-400 font-semibold">Pop-up store in Calamba, Laguna — find us and taste the difference.</span>
    </p>
    <div class="flex justify-center items-end gap-1 mt-2 mb-12 select-none">
      <div class="w-3 h-16 rounded-full bg-gradient-to-t from-orange-900 to-orange-600 opacity-80"></div>
      <div class="w-4 h-24 rounded-full bg-gradient-to-t from-orange-800 to-yellow-600 shadow-lg shadow-orange-600/40"></div>
      <div class="w-5 h-32 rounded-full bg-gradient-to-t from-red-900 to-orange-500 shadow-xl shadow-orange-500/50"></div>
      <div class="w-7 h-40 rounded-full bg-gradient-to-t from-red-800 to-yellow-500 shadow-2xl shadow-yellow-500/40"></div>
      <div class="w-8 h-44 rounded-full bg-gradient-to-t from-orange-900 to-orange-400 shadow-2xl shadow-orange-400/60 scale-105"></div>
      <div class="w-7 h-40 rounded-full bg-gradient-to-t from-red-800 to-yellow-500 shadow-2xl shadow-yellow-500/40"></div>
      <div class="w-5 h-32 rounded-full bg-gradient-to-t from-red-900 to-orange-500 shadow-xl shadow-orange-500/50"></div>
      <div class="w-4 h-24 rounded-full bg-gradient-to-t from-orange-800 to-yellow-600 shadow-lg shadow-orange-600/40"></div>
      <div class="w-3 h-16 rounded-full bg-gradient-to-t from-orange-900 to-orange-600 opacity-80"></div>
    </div>
    <p class="text-xs uppercase tracking-widest text-gray-500">Scroll to discover the full menu</p>
  </div>
</section>
HTML,
            ],
            [
                'key'           => 'star_product',
                'label'         => 'Signature Product Feature',
                'position'      => 'before_products',
                'display_order' => 20,
                'content'       => <<<'HTML'
<section class="py-24 px-6 border-t border-orange-900/30">
  <div class="max-w-5xl mx-auto grid md:grid-cols-2 gap-16 items-center">
    <div class="relative flex items-center justify-center">
      <div class="absolute w-72 h-72 rounded-full opacity-40" style="background:radial-gradient(circle,#f97316,transparent 70%);filter:blur(50px)"></div>
      <div class="relative z-10 w-56 h-56 rounded-full bg-gradient-to-br from-yellow-800 via-orange-700 to-red-900 border-4 border-orange-500 flex items-center justify-center shadow-2xl shadow-orange-600/50">
        <span class="text-8xl select-none">🍔</span>
      </div>
      <div class="absolute top-4 right-8 bg-orange-500 text-black text-xs font-black px-3 py-1 rounded-full uppercase tracking-wider rotate-12 shadow-lg">Best Seller</div>
    </div>
    <div>
      <p class="text-orange-500 text-xs font-bold uppercase tracking-widest mb-3">Signature Burger</p>
      <h2 class="text-4xl sm:text-5xl font-black mb-5 leading-tight">
        The Boys<br><span class="text-orange-400">Classic Smash</span>
      </h2>
      <p class="text-gray-300 leading-relaxed mb-6">
        Double smash patty, melted cheese, caramelized onions, special The Boys sauce — all grilled fresh on the flat-top. Served with <strong class="text-white">crispy fries</strong> or <strong class="text-white">steamed rice</strong> for a complete meal.
      </p>
      <div class="flex flex-wrap gap-3">
        <span class="rounded-full bg-orange-950 border border-orange-800 px-4 py-1.5 text-sm text-orange-300">🔥 Flame-Grilled</span>
        <span class="rounded-full bg-orange-950 border border-orange-800 px-4 py-1.5 text-sm text-orange-300">🧀 Double Cheese</span>
        <span class="rounded-full bg-orange-950 border border-orange-800 px-4 py-1.5 text-sm text-orange-300">🍟 Fries or Rice</span>
      </div>
    </div>
  </div>
</section>
HTML,
            ],
            [
                'key'           => 'concept',
                'label'         => 'Our Story',
                'position'      => 'after_products',
                'display_order' => 10,
                'content'       => <<<'HTML'
<section class="py-24 px-6 border-t border-orange-900/30">
  <div class="max-w-5xl mx-auto text-center mb-16">
    <p class="text-orange-500 text-xs font-bold uppercase tracking-widest mb-3">Our Story</p>
    <h2 class="text-4xl sm:text-5xl font-black mb-6 leading-tight">
      Born in<br><span class="text-orange-400">Calamba, Laguna</span>
    </h2>
    <p class="max-w-2xl mx-auto text-gray-300 leading-relaxed text-lg">
      The Boys is a <strong class="text-white">pop-up burger grill</strong> that started right here in <strong class="text-white">Calamba, Laguna</strong>.
      No frills, no fuss — just <strong class="text-orange-400">real fire, real beef, real flavor</strong>, served fresh every day by the boys who love to grill.
    </p>
  </div>
  <div class="max-w-5xl mx-auto grid sm:grid-cols-3 gap-6">
    <div class="rounded-2xl border border-white/5 p-6 text-center hover:border-orange-700/50 transition-colors" style="background:rgba(255,255,255,0.025)">
      <div class="text-5xl mb-5">📍</div>
      <h3 class="font-bold text-white mb-3">Calamba, Laguna</h3>
      <p class="text-sm text-gray-400 leading-relaxed">Find us at our pop-up spot in Calamba. Follow our page for daily location updates and operating hours.</p>
    </div>
    <div class="rounded-2xl border border-white/5 p-6 text-center hover:border-orange-700/50 transition-colors" style="background:rgba(255,255,255,0.025)">
      <div class="text-5xl mb-5">🔥</div>
      <h3 class="font-bold text-white mb-3">Grilled Fresh Daily</h3>
      <p class="text-sm text-gray-400 leading-relaxed">Every burger is pressed and grilled fresh on order. No reheated patties — only hot, juicy, flame-grilled goodness.</p>
    </div>
    <div class="rounded-2xl border border-white/5 p-6 text-center hover:border-orange-700/50 transition-colors" style="background:rgba(255,255,255,0.025)">
      <div class="text-5xl mb-5">🤙</div>
      <h3 class="font-bold text-white mb-3">Made by The Boys</h3>
      <p class="text-sm text-gray-400 leading-relaxed">A group of friends who turned their love for grilling into a business. Every burger is made with pride.</p>
    </div>
  </div>
</section>
HTML,
            ],
            [
                'key'           => 'tagline',
                'label'         => 'Tagline Banner',
                'position'      => 'after_products',
                'display_order' => 20,
                'content'       => <<<'HTML'
<section class="py-20 px-6 text-center relative overflow-hidden">
  <div class="absolute inset-0 pointer-events-none" style="background:linear-gradient(180deg,transparent,rgba(249,115,22,0.08),transparent)"></div>
  <div class="relative z-10 max-w-3xl mx-auto">
    <p class="text-3xl sm:text-4xl font-black leading-tight text-white mb-4">
      "Kain na, bro — <span class="text-orange-400">mas solid habang mainit!</span>"
    </p>
    <p class="text-gray-400 text-sm uppercase tracking-widest">The Boys · Grilled Burgers · Calamba, Laguna</p>
  </div>
</section>
HTML,
            ],
        ];

        foreach ($sections as $data) {
            PageSection::updateOrCreate(['key' => $data['key']], $data);
        }
    }
}
