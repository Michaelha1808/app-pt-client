# ML pipeline — cải thiện nhận diện món ăn

Pipeline **OFFLINE** chuẩn bị dữ liệu và (tùy chọn) fine-tune model nhận diện món ăn.
**Không** chạy ở production — inference vẫn do Laravel gọi Gemini đảm nhiệm. Xem toàn cảnh ở
[`docs/spec-food-model-improvement.md`](../docs/spec-food-model-improvement.md).

> **Quan trọng:** Gemini qua API là model đóng, **không train được trọng số qua API thường**.
> "Fine-tune thật" chỉ có trên **Vertex AI** và là bước cuối — chỉ làm khi prompt + nutrition DB
> đã hết dư địa. Cú hích chính xác lớn nhất đến từ grounding (nutrition DB) + feedback loop,
> không phải fine-tune.

## Luồng dữ liệu

```
food_detection_samples (Postgres)        ← Bước 1: app thu thập AI đoán + user sửa + ảnh
        │  export_dataset.py
        ▼
ml/out/dataset.jsonl  + ml/out/images/   ← dataset trung gian (độc lập nhà cung cấp)
        │  (tùy chọn) convert + upload GCS
        ▼
Vertex AI supervised tuning (Gemini)     ← Bước 4: chỉ khi đủ vài trăm ví dụ
```

## Cài đặt

```bash
cd ml
python -m venv .venv
# Windows: . .venv/Scripts/activate    |  Linux/mac: source .venv/bin/activate
pip install -r requirements.txt
```

## Export dataset

Postgres của môi trường dev đã expose ở `localhost:5432` (xem `docker-compose.yml`). Script đọc
thông tin DB từ `../.env` (tự đổi `DB_HOST=postgres` → `127.0.0.1` khi chạy ở host).

```bash
python export_dataset.py                  # tất cả mẫu đã chốt
python export_dataset.py --only-corrections   # chỉ mẫu user có sửa (tín hiệu mạnh nhất)
```

Kết quả:
- `out/dataset.jsonl` — mỗi dòng: `{instruction, target:{dishes:[...]}, image_path|text_input}`
- `out/images/` — ảnh tương ứng (copy từ `storage/app/private/food-samples/`)

Mỗi ví dụ dựng "đáp án vàng" bằng cách áp chỉnh sửa của user (đổi tên / sửa calo / bỏ chọn món
nhận nhầm) lên kết quả AI đoán ban đầu.

## Fine-tune trên Vertex AI (bước cuối, cần Google Cloud)

Chưa script hoá vì cần tài khoản GCP. Các bước:

1. **Đủ dữ liệu:** nên có **≥ vài trăm** ví dụ có sửa (`--only-corrections`). Dưới mức đó thì
   ưu tiên mở rộng nutrition DB + few-shot thay vì fine-tune.
2. **Upload ảnh lên GCS:** Vertex tuning yêu cầu ảnh nằm trên `gs://`. Upload `out/images/` lên
   một bucket, rồi đổi `image_path` → `gs://<bucket>/...` (`fileData.fileUri`).
3. **Chuyển sang định dạng Vertex** (JSONL hội thoại):
   ```json
   {"contents":[
     {"role":"user","parts":[
       {"fileData":{"mimeType":"image/jpeg","fileUri":"gs://.../123.jpg"}},
       {"text":"<instruction>"}]},
     {"role":"model","parts":[{"text":"<target dạng chuỗi JSON>"}]}
   ]}
   ```
4. **Tạo tuning job** (`google-cloud-aiplatform`) trên base `gemini-2.0-flash`, chia train/validation.
5. **Đánh giá** model đã tune trên tập giữ lại; chỉ thay model production qua
   `Settings` (admin `ai.model`) nếu độ chính xác tên món cải thiện rõ.

## Lưu ý
- `out/` chứa ảnh đồ ăn của người dùng — **không commit** (đã đưa vào `.gitignore`). Coi như dữ liệu riêng tư.
- Dataset hiện có thể trống cho tới khi người dùng thật quét & sửa món.
