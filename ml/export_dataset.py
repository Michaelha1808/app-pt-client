#!/usr/bin/env python3
"""
Export dataset nhận diện món ăn từ bảng `food_detection_samples` ra JSONL để fine-tune.

Mỗi mẫu có `corrected_dishes` (user đã chốt) được dùng làm "đáp án vàng": ta merge chỉnh sửa
của user (đổi tên / sửa calo / bỏ chọn) lên `ai_dishes` để dựng lại mảng món đúng theo schema
mà model `detect` cần sinh ra. Input = ảnh (hoặc mô tả text), output = JSON {"dishes":[...]}.

Chạy:
    python export_dataset.py                # xuất tất cả mẫu có corrected_dishes
    python export_dataset.py --only-corrections   # chỉ mẫu user có sửa (has_correction)

Kết quả:
    ml/out/dataset.jsonl     — mỗi dòng 1 ví dụ {input, target, image_path}
    ml/out/images/           — ảnh được copy ra (nếu mẫu là ảnh)
    in ra thống kê số mẫu / tỉ lệ có sửa.

Đây là dữ liệu trung gian, độc lập nhà cung cấp. Bước chuyển sang định dạng Vertex AI
(thêm GCS URI) xem README.md — cần tài khoản Google Cloud nên tách riêng.
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
from pathlib import Path

import psycopg2
import psycopg2.extras

try:
    from dotenv import dotenv_values
except ImportError:
    dotenv_values = None

ROOT = Path(__file__).resolve().parent.parent          # gốc project Laravel
OUT_DIR = Path(__file__).resolve().parent / "out"
IMAGES_DIR = OUT_DIR / "images"
STORAGE_PRIVATE = ROOT / "storage" / "app" / "private"  # disk 'local'

# Hướng dẫn cho model — bản rút gọn của prompt detect ở backend.
INSTRUCTION = (
    "Liệt kê tất cả món ăn/đồ uống trong ảnh. Trả về JSON "
    '{"dishes":[{"food_name","unit_type","unit_label","quantity_default",'
    '"serving","calories","protein","carbs","fat","sodium","confidence"}]}. '
    "Tên món bằng tiếng Việt, calo cho 1 đơn vị chuẩn."
)


def db_config() -> dict:
    """Đọc cấu hình DB từ .env của Laravel, mặc định trỏ localhost (Postgres đã expose 5432)."""
    env = {}
    env_file = ROOT / ".env"
    if dotenv_values and env_file.exists():
        env = dotenv_values(env_file)

    def get(key, default):
        return os.environ.get(key) or env.get(key) or default

    # Trong container DB_HOST=postgres; chạy script ở host nên dùng localhost.
    host = get("DB_HOST", "127.0.0.1")
    if host == "postgres":
        host = "127.0.0.1"

    return {
        "host": host,
        "port": int(get("DB_PORT", "5432")),
        "dbname": get("DB_DATABASE", "pt_client"),
        "user": get("DB_USERNAME", "pt_user"),
        "password": get("DB_PASSWORD", "secret"),
    }


def build_gold_dishes(ai_dishes: list, corrected: list) -> list:
    """Áp chỉnh sửa user lên ai_dishes → mảng món đúng (full schema). Bỏ món user không chọn."""
    gold = []
    for i, ai in enumerate(ai_dishes):
        c = corrected[i] if i < len(corrected) else None
        if c is not None and c.get("selected") is False:
            continue  # AI nhận nhầm / user không ăn → loại
        d = dict(ai)
        if c is not None:
            if c.get("food_name"):
                d["food_name"] = c["food_name"]
            if c.get("calories") is not None:
                d["calories"] = int(c["calories"])
        gold.append(d)
    return gold


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--only-corrections", action="store_true",
                        help="Chỉ xuất mẫu user thực sự có sửa (has_correction = true)")
    args = parser.parse_args()

    OUT_DIR.mkdir(exist_ok=True)
    IMAGES_DIR.mkdir(exist_ok=True)

    where = "corrected_dishes IS NOT NULL"
    if args.only_corrections:
        where += " AND has_correction = true"

    conn = psycopg2.connect(**db_config())
    cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
    cur.execute(
        f"SELECT id, input_type, image_path, text_input, ai_dishes, corrected_dishes "
        f"FROM food_detection_samples WHERE {where} ORDER BY id"
    )
    rows = cur.fetchall()

    n_total = n_image = n_text = n_skipped = 0
    out_path = OUT_DIR / "dataset.jsonl"

    with out_path.open("w", encoding="utf-8") as f:
        for r in rows:
            ai = r["ai_dishes"] or []
            corrected = r["corrected_dishes"] or []
            gold = build_gold_dishes(ai, corrected)
            if not gold:
                n_skipped += 1
                continue

            example = {
                "instruction": INSTRUCTION,
                "target": {"dishes": gold},
            }

            if r["input_type"] == "image" and r["image_path"]:
                src = STORAGE_PRIVATE / r["image_path"]
                if not src.exists():
                    n_skipped += 1
                    continue
                dst_name = f"{r['id']}{src.suffix or '.jpg'}"
                shutil.copy2(src, IMAGES_DIR / dst_name)
                example["image_path"] = f"images/{dst_name}"
                n_image += 1
            elif r["text_input"]:
                example["text_input"] = r["text_input"]
                n_text += 1
            else:
                n_skipped += 1
                continue

            f.write(json.dumps(example, ensure_ascii=False) + "\n")
            n_total += 1

    cur.close()
    conn.close()

    print(f"✅ Xuất {n_total} ví dụ → {out_path}")
    print(f"   ảnh: {n_image} · text: {n_text} · bỏ qua: {n_skipped}")
    if n_total == 0:
        print("⚠️  Dataset trống — cần người dùng quét & sửa món thực tế trước (Bước 1).")
    elif n_total < 100:
        print(f"⚠️  Mới {n_total} ví dụ. Fine-tune Vertex AI nên có ≥ vài trăm ví dụ mới hiệu quả.")


if __name__ == "__main__":
    main()
