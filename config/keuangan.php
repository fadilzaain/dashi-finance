<?php

return [
    // Jendela pencarian pindah dana antar rekening: kredit boleh tercatat sampai sekian JAM
    // sesudah debit. Bawaan 24 jam (uji data September 2026: selisih terlama hanya 29 menit).
    // Isi lewat .env (KEU_JENDELA_TRANSFER_JAM), rentang 1 sampai 720.
    'jendela_transfer_jam' => (int) env('KEU_JENDELA_TRANSFER_JAM', 24),
];