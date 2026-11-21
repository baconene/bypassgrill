# Homepage 3D models

The homepage hero and featured menu load models from this directory. Each model spins as visitors
scroll through its dish. Put a transparent `.webp` with the same name alongside each `.glb` to use
as its loading and fallback image. Dishes without a model use their cutout or POS photo.

| Dish                     | File name                    |
| ------------------------ | ---------------------------- |
| Pork Monster Ribs (hero) | `pork-monster-ribs.glb`      |
| Jamaican Chicken Jerk    | `chicken-jerk.glb`           |
| Grilled Pork Liempo      | `grilled-pork-liempo.glb`    |
| Rustic Chicken Skewers   | `rustic-chicken-skewers.glb` |

## Getting a realistic model

Scan the real dish with a phone photogrammetry app such as Polycam, Luma AI or KIRI Engine:
walk slowly around the plated ribs in good, even light, taking 60–150 photos, then export as GLB.

For the web, keep it light: under about 8 MB (ideally 2–4 MB), textures 2048 px or smaller,
and the dish centred on the origin. Tools like gltf.report or `gltf-transform optimize` can compress it.
