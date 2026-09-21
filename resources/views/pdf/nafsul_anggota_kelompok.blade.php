{{--
    Daftar anggota satu kelompok.

    Dicetak dari modal "Lihat Anggota" di halaman Anggota Kelompok, dan
    kolom serta urutannya sengaja SAMA PERSIS dengan yang tampil di layar —
    petugas membandingkan kertas dengan layar baris per baris, dan kolom yang
    berbeda urutan atau isinya membuat perbandingan itu mustahil.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jumlah Anggota Berdasarkan Ketua Kelompok</title>
    <style>
        @page { margin: 18mm 14mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #111;
        }

        .garis { border-bottom: 1.5px solid #111; margin: 6px 0 10px; }

        .judul {
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
        }

        /* Identitas ketua RATA KIRI, label sejajar dalam satu kolom tetap
           supaya titik duanya berbaris dan nilainya mudah dibaca menurun. */
        table.ketua { border-collapse: collapse; margin-bottom: 8px; }
        table.ketua td { padding: 1px 0; font-size: 9px; }
        table.ketua td.label { width: 78px; }
        table.ketua td.pemisah { width: 10px; }

        table.daftar { width: 100%; border-collapse: collapse; }

        /* Tanpa garis kecuali KEPALA tabel: baris yang dikotak-kotak penuh
           membuat lembar berisi ratusan nama terbaca dua kali lebih padat
           daripada isinya. Kepala tabelnya tetap bergaris supaya batas antara
           judul kolom dan isinya jelas. */
        table.daftar th {
            border-top: 0.8px solid #444;
            border-bottom: 0.8px solid #444;
            padding: 4px 5px;
            font-size: 8px;
            text-transform: uppercase;
            text-align: left;
        }

        table.daftar td {
            border: none;
            padding: 3px 5px;
            vertical-align: top;
        }

        /* Kepala tabel diulang di tiap halaman — daftar sekelompok bisa
           berlembar-lembar, dan halaman kedua tanpa kepala kolom hanya jadi
           deretan angka tanpa keterangan. */
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        .tengah { text-align: center; }
        /* Nomor & periode tidak boleh patah barisnya: "01/" di satu baris dan
           "2026" di baris berikutnya terbaca sebagai dua nilai berbeda. */
        .mono { font-family: DejaVu Sans Mono, monospace; white-space: nowrap; }

        /* Dua kolom paling sempit sekaligus paling jarang dibaca — hurufnya
           dikecilkan supaya lebarnya cukup tanpa memakan kolom Alamat. */
        .kecil { font-size: 8px; }

        .kosong { color: #888; }

        .kaki {
            margin-top: 10px;
            font-size: 8px;
            color: #444;
        }
    </style>
    @include('pdf.partials.kop_nafsul_style')
</head>
<body>

@include('pdf.partials.kop_nafsul')

<div class="garis"></div>

<div class="judul">JUMLAH ANGGOTA BERDASARKAN KETUA KELOMPOK</div>

<table class="ketua">
    <tr>
        <td class="label">No Ketua</td>
        <td class="pemisah">:</td>
        <td class="mono">{{ $ketua->code }}</td>
    </tr>
    <tr>
        <td class="label">Nama Ketua</td>
        <td class="pemisah">:</td>
        <td>{{ $ketua->name }}</td>
    </tr>
    <tr>
        <td class="label">Jumlah Anggota</td>
        <td class="pemisah">:</td>
        <td>{{ count($baris) }} orang</td>
    </tr>
</table>

<table class="daftar">
    <thead>
        <tr>
            <th style="width: 22px" class="tengah">No</th>
            <th style="width: 66px">No. Anggota</th>
            <th style="width: 150px">Nama Anggota</th>
            <th>Alamat</th>
            <th style="width: 78px" class="kecil">Keterangan</th>
            <th style="width: 50px" class="tengah kecil">Iuran Terakhir</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($baris as $i => $b)
            <tr>
                <td class="tengah">{{ $i + 1 }}</td>
                <td class="mono">{{ $b['no_anggota'] ?: '-' }}</td>
                <td>{{ $b['nama'] }}</td>
                <td>{{ $b['alamat'] ?: '-' }}</td>
                <td class="kecil">{{ $b['keterangan'] ?: '-' }}</td>
                {{-- Belum pernah beriuran ditulis "-", bukan dikosongkan: sel
                     kosong di kertas terbaca seperti kolom yang lupa diisi. --}}
                <td class="tengah mono kecil">{{ $b['iuran_terakhir'] ?: '-' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="tengah kosong" style="padding: 14px">
                    Kelompok ini belum punya anggota.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="kaki">Dicetak {{ $tanggalCetak }}</div>

</body>
</html>
