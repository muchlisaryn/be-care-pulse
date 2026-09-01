{{--
    Kuitansi pembayaran JASA KETUA KELOMPOK.

    Berbeda dari pdf.nafsul_biling: biling merinci iuran seluruh anggota pada
    sebuah kuitansi setoran, sedangkan lembar ini adalah tanda terima satu arah —
    bukti bahwa ketua kelompok sudah menerima komisinya. Karena itu isinya cuma
    beberapa baris keterangan dan ruang tanda tangan penerima.

    Menerima `$lembar`: SATU berkas bisa memuat banyak kuitansi, satu per
    halaman (lihat RekapJasaController::kuitansiMassal). Cetak satuan memakai
    blade yang sama dengan array berisi satu elemen, supaya lembarnya tidak
    pernah berbeda antara cetak satuan dan cetak massal.

    Sama seperti biling: HITAM PUTIH seluruhnya (printer kantor lazimnya
    monokrom), tata letak memakai tabel karena dompdf tidak mendukung flexbox
    maupun grid, dan JENIS HURUFNYA satu macam — DejaVu Sans, persis yang
    dipakai biling. Jangan menambah font-family lain di sini: dokumen Nafsul
    harus terlihat berasal dari satu aplikasi yang sama.

    Dicetak MELINTANG (landscape, diatur di RekapJasaController::render).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kuitansi Jasa{{ count($lembar) === 1 ? ' '.$lembar[0]['header']->transaction_number : '' }}</title>
    <style>
        /* Lembar melintang: margin kiri-kanan lebih lega daripada atas-bawah,
           supaya barisnya tidak terbentang selebar halaman. */
        @page { margin: 14mm 24mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #000;
            margin: 0;
        }

        /* Kop surat: rata tengah, memenuhi lebar halaman. Ukuran & spasinya
           disamakan dengan pdf.nafsul_biling. */
        .kop { text-align: center; }
        .kop-nama {
            font-size: 14px;
            font-weight: bold;
            letter-spacing: .3px;
        }
        .kop-unit {
            font-size: 11px;
            font-weight: bold;
            letter-spacing: .3px;
            margin-top: 1px;
        }
        .kop-alamat { font-size: 8.5px; }

        .garis { border-bottom: 1.5px solid #000; margin: 6px 0 16px; }

        .judul {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            letter-spacing: .4px;
            margin-bottom: 20px;
        }

        /* Isi kuitansi dicetak lebih besar daripada teks dokumen Nafsul lain.
           Bukan tidak konsisten: lembar ini cuma lima baris di atas kertas
           melintang, dan pada 11px seperti biling ia terbaca seperti catatan
           kaki di tengah halaman kosong. Yang tetap dijaga sama adalah JENIS
           hurufnya. */
        table.isi { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.isi td { padding: 9px 0; vertical-align: top; }
        table.isi td.label { width: 22%; }
        table.isi td.pemisah { width: 2%; }

        /* Nilai yang diisi: bergaris bawah putus supaya terbaca sebagai isian
           lembar, bukan sebagai kalimat biasa. */
        .isian { border-bottom: 1px dotted #000; display: block; padding-bottom: 3px; }

        /* Jarak lebar sebelum blok tanda tangan: memberi ruang bagi petugas
           menuliskan catatan tangan di bawah baris Terbilang bila perlu. */
        table.ttd { width: 100%; margin-top: 48px; border-collapse: collapse; font-size: 13px; }
        /* Rata KANAN, bukan sekadar digeser lewat lebar sel: dengan lebar sel
           saja bloknya berhenti di tengah halaman melintang yang lebar, dan
           tanda tangan yang mengambang di tengah bukan tempatnya. */
        table.ttd td.ttd-kanan { vertical-align: top; text-align: right; }
        /* Blok tanda tangan: kotak berlebar tetap yang MENEMPEL KANAN (karena
           selnya rata kanan), isinya rata KIRI — "Tgl Cetak ..." dan "Penerima"
           sejajar di tepi kiri kotak yang sama, bukan saling menggantung di
           ujung yang berbeda. Lebarnya dipatok supaya garis tanda tangannya
           tidak ikut memanjang-memendek mengikuti panjang nama. */
        .blok-ttd {
            display: inline-block;
            text-align: left;
            width: 230px;
        }
        /* Ruang tanda tangan basah antara "Penerima" dan nama penerimanya.
           Setinggi ini supaya tanda tangan tidak bertabrakan dengan keduanya —
           pada 54px sebelumnya coretan pena lazim melewati garis namanya. */
        .ruang-ttd { height: 80px; }
        /* Namanya sendiri tetap rata tengah di atas garis. `display: block` agar
           garis atasnya memenuhi lebar kotak, bukan cuma selebar namanya. */
        .nama-ttd {
            border-top: 1px solid #000;
            padding-top: 4px;
            display: block;
            text-align: center;
        }

        /* Satu kuitansi per halaman. `page-break-after` dipasang pada SETIAP
           lembar kecuali yang terakhir — memasangnya di semua lembar menyisakan
           satu halaman kosong di ujung berkas. */
        .lembar { page-break-inside: avoid; }
        .lembar.putus { page-break-after: always; }
    </style>
</head>
<body>

@foreach ($lembar as $l)
<div class="lembar {{ $loop->last ? '' : 'putus' }}">

    <div class="kop">
        <div class="kop-nama">RUMAH SAKIT ISLAM JAKARTA PONDOK KOPI</div>
        <div class="kop-unit">UNIT LAYANAN NAFSUL MUTMAINAH</div>
        <div class="kop-alamat">Jl. Raya Pondok Kopi - Jakarta Timur 13460</div>
        <div class="kop-alamat">tlp. 021--61-471, 0630654 ext 5111 fax 021-0611101</div>
    </div>

    <div class="garis"></div>

    <div class="judul">KUITANSI</div>

    <table class="isi">
        <tr>
            <td class="label">No Pembayaran</td>
            <td class="pemisah">:</td>
            <td><span class="isian">{{ $l['header']->transaction_number }}</span></td>
        </tr>
        <tr>
            <td class="label">Telah Terima Dari</td>
            <td class="pemisah">:</td>
            <td><span class="isian">Nafsul Mutmainnah RS. Islam Jakarta</span></td>
        </tr>
        <tr>
            <td class="label">Uang Sejumlah</td>
            <td class="pemisah">:</td>
            <td><span class="isian">{{ $l['nominal'] }}</span></td>
        </tr>
        <tr>
            <td class="label">Guna Pembayaran</td>
            <td class="pemisah">:</td>
            <td><span class="isian">Jasa Ketua Kelompok</span></td>
        </tr>
        <tr>
            <td class="label">Terbilang</td>
            <td class="pemisah">:</td>
            {{-- Terbilang tetap dicetak walau nominalnya sudah tertulis di atas:
                 pada tanda terima, angka dan hurufnya harus saling mengunci agar
                 angkanya tidak bisa diubah sepihak setelah ditandatangani.

                 `ucwords`, bukan `ucfirst`: tiap kata berhuruf besar di awal
                 ("Sepuluh Ribu Delapan Ratus Rupiah"). Dilakukan DI SINI, bukan
                 di Terbilang::rupiah — nilai mentahnya tetap huruf kecil supaya
                 masih bisa disisipkan di tengah kalimat oleh pemakai lain. --}}
            <td><span class="isian">{{ ucwords($l['terbilang']) }}</span></td>
        </tr>
    </table>

    <table class="ttd">
        <tr>
            <td style="width: 55%"></td>
            <td class="ttd-kanan" style="width: 45%">
                {{-- Tgl Cetak ikut MASUK ke dalam blok, bukan berdiri sendiri di
                     luarnya: hanya dengan begitu ia benar-benar sejajar dengan
                     "Penerima" di bawahnya. --}}
                <div class="blok-ttd">
                    Tgl Cetak {{ $l['tanggalCetak'] }}<br>
                    Penerima
                    <div class="ruang-ttd"></div>
                    <span class="nama-ttd">{{ $l['ketua'] }}</span>
                </div>
            </td>
        </tr>
    </table>

</div>
@endforeach

</body>
</html>
