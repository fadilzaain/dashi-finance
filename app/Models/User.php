<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_KEUANGAN = 'keuangan';

    public const LABEL_ROLE = [
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_KEUANGAN => 'Keuangan',
    ];

    public const STATUS_AKTIF = 'aktif';
    public const STATUS_NONAKTIF = 'nonaktif';

    // Akun hanya dibuat admin lewat server (tidak ada pendaftaran mandiri),
    // jadi kolom role/status tidak pernah diisi dari input form.
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'wajib_ganti_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed', // otomatis di-hash (bcrypt) saat diisi
            'wajib_ganti_password' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isAktif(): bool
    {
        return $this->status === self::STATUS_AKTIF;
    }
}