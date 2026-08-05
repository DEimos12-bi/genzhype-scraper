#!/usr/bin/env python3
"""Depth-parallax CPU benchmark (TREATMENT V2 gate, 2026-08-05).

Measures the two costs of the planned 2.5D parallax treatment on a real
GitHub Actions runner, because NO published CPU latency exists for
Depth-Anything-V2-Small (the repo's table is CUDA-only):

  1. depth inference — Depth-Anything-V2-Small ONNX (Apache-2.0 checkpoint,
     the ONLY DA2 size we may ship; Base/Large are CC-BY-NC), preprocessing
     copied VERBATIM from fabio-sim/Depth-Anything-ONNX dynamo.py infer()
     (the file itself says "implement this part in your chosen language").
     onnxruntime + opencv only — NO torch (lean-runner rule from r25).
  2. the parallax warp itself — cv2.remap of a 1080x1920 frame through
     depth-displaced grids, 100 frames (~4.2s of video at 24fps). This is
     the exact production warp, not a proxy.

Usage: depth_bench.py <model.onnx> <image> [image...]
Prints one RESULT line per measurement; the workflow commits the output.
"""
import sys
import time

import cv2
import numpy as np
import onnxruntime as ort


def preprocess(img):
    # verbatim from dynamo.py infer(): BGR->RGB /255, 518 cubic, ImageNet
    # mean/std, CHW float32 batch
    image = cv2.cvtColor(img, cv2.COLOR_BGR2RGB) / 255.0
    image = cv2.resize(image, (518, 518), interpolation=cv2.INTER_CUBIC)
    image = (image - [0.485, 0.456, 0.406]) / [0.229, 0.224, 0.225]
    return image.transpose(2, 0, 1)[None].astype("float32")


def depth_for(sess, iname, img):
    x = preprocess(img)
    out = sess.run(None, {iname: x})[0]
    d = out[0] if out.ndim == 3 else out[0, 0]
    d = (d - d.min()) / max(1e-6, float(d.max() - d.min()))
    h, w = img.shape[:2]
    return cv2.resize(d.astype("float32"), (w, h), interpolation=cv2.INTER_CUBIC)


def cover(img, W=1080, H=1920):
    h, w = img.shape[:2]
    s = max(W / w, H / h)
    r = cv2.resize(img, (int(w * s) + 1, int(h * s) + 1))
    y0 = (r.shape[0] - H) // 2
    x0 = (r.shape[1] - W) // 2
    return r[y0:y0 + H, x0:x0 + W]


def main():
    model, paths = sys.argv[1], sys.argv[2:]
    t0 = time.time()
    sess = ort.InferenceSession(model, providers=["CPUExecutionProvider"])
    iname = sess.get_inputs()[0].name
    print(f"RESULT session_load_s={time.time() - t0:.2f}")

    first = None
    for k, p in enumerate(paths):
        img = cv2.imread(p)
        if img is None:
            print(f"RESULT img{k} unreadable {p}")
            continue
        t0 = time.time()
        d = depth_for(sess, iname, img)
        dt = time.time() - t0
        tag = " (warm-up)" if first is None else ""
        print(f"RESULT img{k} {img.shape[1]}x{img.shape[0]} depth_s={dt:.2f}{tag}")
        if first is None:
            first = (img, d)

    if first is None:
        print("RESULT no readable images — warp bench skipped")
        return
    img, _ = first
    frame = cover(img)                      # 1080x1920, production geometry
    depth = depth_for(sess, iname, frame)   # depth on the exact frame -> grids align
    H, W = frame.shape[:2]
    yy, xx = np.indices((H, W), dtype=np.float32)
    AMP = 18.0  # px of max foreground shift — the visible parallax strength
    t0 = time.time()
    n = 100
    for i in range(n):
        ph = i / n * 2 * np.pi
        dx = np.float32(np.sin(ph)) * AMP
        dy = np.float32(np.cos(ph)) * AMP * 0.35
        map_x = xx + depth * dx
        map_y = yy + depth * dy
        cv2.remap(frame, map_x, map_y, cv2.INTER_LINEAR,
                  borderMode=cv2.BORDER_REFLECT)
    dt = time.time() - t0
    print(f"RESULT warp_{n}_frames_s={dt:.2f} per_frame_ms={dt / n * 1000:.1f}")
    print(f"RESULT est_full_video_warp_s={dt / n * 1100:.1f} (1100 frames ~ 46s video)")


if __name__ == "__main__":
    main()
