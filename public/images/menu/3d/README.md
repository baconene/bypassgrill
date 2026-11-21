# Homepage 3D models

Drop a `.glb` file here and the homepage hero shows it as a real 3D product that spins as visitors
scroll (no code change, no rebuild). Until a model exists, the hero uses the cutout or POS photo.

| Dish                     | File name               |
| ------------------------ | ----------------------- |
| Pork Monster Ribs (hero) | `pork-monster-ribs.glb` |

## Getting a realistic model

Scan the real dish with a phone photogrammetry app such as Polycam, Luma AI or KIRI Engine:
walk slowly around the plated ribs in good, even light, taking 60–150 photos, then export as GLB.

For the web, keep it light: under about 8 MB (ideally 2–4 MB), textures 2048 px or smaller,
and the dish centred on the origin. Tools like gltf.report or `gltf-transform optimize` can compress it.
