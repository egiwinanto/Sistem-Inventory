# StockBite Inventory F&B v1.3.0

Aplikasi sistem informasi inventory dan kasir F&B yang responsif untuk desktop dan mobile. Dibuat menggunakan PHP 8, SQLite, CSS, dan JavaScript murni, sehingga tidak memerlukan Composer, Node.js, atau proses build.

## Modul Utama

### 1. Dashboard

- Ringkasan jumlah bahan aktif dan nilai persediaan.
- Omzet dan jumlah pesanan hari ini.
- Peringatan stok minimum.
- Aktivitas mutasi stok terbaru.
- Bahan yang paling banyak digunakan.

### 2. Kasir/POS

- Digunakan oleh owner dan staff kasir.
- Staff langsung diarahkan ke kasir setelah login.
- Pilihan menu berbentuk kartu yang nyaman digunakan di ponsel.
- Keranjang, jumlah pesanan, diskon nominal, dan catatan.
- Pilihan makan di tempat, bawa pulang, atau delivery.
- Pembayaran tunai, QRIS, transfer, dan kartu debit.
- Perhitungan uang diterima dan kembalian otomatis.
- Nomor invoice otomatis dan cetak struk.
- Riwayat transaksi kasir.
- Penjualan otomatis mengurangi stok bahan berdasarkan resep menu.

### 3. Data Persediaan

- Data bahan, SKU, kategori, satuan, lokasi, stok minimum, dan biaya rata-rata.
- Supplier.
- Stok masuk, stok keluar, rusak/terbuang, dan penyesuaian stok.
- Validasi agar stok tidak menjadi negatif.
- Biaya rata-rata diperbarui otomatis ketika stok masuk.
- Pemantauan batch dan tanggal kedaluwarsa.

### 4. Menu dan Resep

- Data menu, kode, harga jual, dan status aktif.
- Komposisi resep per porsi.
- Perhitungan jumlah porsi yang dapat dijual berdasarkan stok bahan.
- Empat menu contoh siap digunakan.

### 5. Laporan Penjualan

- Filter tanggal awal dan akhir.
- Filter metode pembayaran, jenis pesanan, dan kasir.
- Pencarian berdasarkan invoice atau nama pelanggan.
- Omzet kotor, diskon, omzet bersih, jumlah transaksi, item terjual, HPP, dan estimasi laba kotor.
- Tren penjualan harian.
- Ringkasan metode pembayaran.
- Daftar menu terlaris.
- Detail seluruh transaksi dan akses ke struk.
- Ekspor CSV dan cetak laporan.

### 6. Laporan Persediaan

- Filter periode dan jenis mutasi.
- Nilai stok masuk dan pemakaian.
- Detail mutasi bahan.
- Ekspor CSV dan cetak.

## Cara Menjalankan di Windows

1. Ekstrak folder `stockbite-inventory`.
2. Klik dua kali `START-STOCKBITE.bat`.
3. Browser akan membuka `http://127.0.0.1:8080`.

Launcher akan menggunakan PHP yang tersedia di PATH atau mencoba `C:\xampp\php\php.exe`.

## Menjalankan Melalui XAMPP

1. Salin folder aplikasi ke `C:\xampp\htdocs\stockbite-inventory`.
2. Jalankan Apache dari XAMPP Control Panel.
3. Buka `http://localhost/stockbite-inventory`.

### Mengaktifkan SQLite

Bila muncul pesan PDO SQLite belum aktif, buka `C:\xampp\php\php.ini`, lalu aktifkan:

```ini
extension=pdo_sqlite
extension=sqlite3
```

Simpan perubahan dan restart Apache.

## Akun Awal

| Peran | Email | Password |
|---|---|---|
| Owner | `admin@stockbite.local` | `password` |
| Staff Kasir | `staff@stockbite.local` | `password` |

Email dan password tidak ditampilkan atau diisikan otomatis pada form login.

## Database

Database berada di `storage/stockbite.sqlite`. Pastikan folder tersebut dapat ditulis oleh PHP.

Untuk mengembalikan data awal, hapus `storage/stockbite.sqlite`, lalu buka aplikasi kembali. Sistem akan membuat database, bahan, supplier, menu, dan resep contoh secara otomatis.

## Struktur Folder

```text
stockbite-inventory/
├── assets/              CSS dan JavaScript
├── database/            skema lengkap SQLite
├── src/                 bootstrap, database, helper, dan layout
├── storage/             database SQLite aplikasi
├── index.php            seluruh modul aplikasi
├── router.php           router untuk PHP built-in server
├── START-STOCKBITE.bat  launcher Windows
└── start.sh             launcher Linux/macOS
```

## Keamanan dan Produksi

Sebelum digunakan pada server publik, ganti password akun awal, gunakan HTTPS, batasi akses ke server, dan buat backup rutin file `storage/stockbite.sqlite`.

Tombol logout tersedia di kanan atas, sidebar desktop, dan navigasi bawah mobile.


## Alur Login dan Mobile

- Aplikasi selalu dimulai dari halaman login ketika URL utama dibuka.
- Sesi lama tidak langsung membawa pengguna ke dashboard.
- Setelah login, owner diarahkan ke dashboard dan staff diarahkan ke kasir.
- Tampilan login, dashboard, kasir, tabel, modal, dan laporan telah dioptimalkan untuk ponsel.

## Perbaikan versi 1.3.1

- Pengguna yang belum login diarahkan ke form login.
- Setelah login, perpindahan menu tidak lagi menghapus sesi.
- Membuka `?page=login` saat sudah login akan diarahkan kembali ke dashboard/kasir.
- Sesi hanya dihapus saat tombol Logout ditekan.
- Nama cookie sesi diperbarui agar tidak bentrok dengan instalasi versi lama.

