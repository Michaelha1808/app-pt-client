<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bảng MET (Metabolic Equivalent of Task)
    |--------------------------------------------------------------------------
    | Dùng ước lượng calo đốt cho buổi tập LOG THỦ CÔNG khi user không tự nhập:
    |   calories ≈ MET × cân_nặng_kg × (duration_seconds / 3600)
    | Giá trị tham khảo, có thể chỉnh. Key = loại bài tập (type) hợp lệ.
    */
    'met' => [
        'walk'    => 3.5,
        'run'     => 9.8,
        'ride'    => 7.5,   // đạp xe
        'swim'    => 8.0,
        'workout' => 5.0,   // gym / tạ
        'yoga'    => 3.0,
        'hike'    => 6.0,
        'other'   => 4.0,
    ],

    // Cân nặng mặc định (kg) khi profile user chưa có weight_kg.
    'default_weight_kg' => 60,

    // Số ngày backfill khi kết nối provider lần đầu (Phase B).
    'backfill_days' => 7,

];
