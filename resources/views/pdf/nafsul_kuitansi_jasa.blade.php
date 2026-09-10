{{--
    Kuitansi pembayaran JASA KETUA KELOMPOK.

    Berbeda dari pdf.nafsul_biling: biling merinci iuran seluruh anggota pada
    sebuah kuitansi setoran, sedangkan lembar ini adalah tanda terima satu arah —
    bukti bahwa ketua kelompok sudah menerima komisinya. Karena itu isinya cuma
    beberapa baris keterangan dan ruang tanda tangan penerima.

    Menerima `$lembar`: SATU berkas bisa memuat banyak kuitansi (lihat
    RekapJasaController::kuitansiMassal). Cetak satuan memakai blade yang sama
    dengan array berisi satu elemen, supaya lembarnya tidak pernah berbeda antara
    cetak satuan dan cetak massal.

    Tata letak kertas: A4 MELINTANG (diatur di RekapJasaController::render),
    dibagi DUA LAJUR atas-bawah — dua kuitansi per halaman, dipisah garis
    putus-putus tepat di tengah sebagai batas potong. Hasil potongannya berupa
    lembar lebar-pendek (±297 × 105 mm), bentuk yang sama dengan buku kuitansi.

    Sama seperti biling: HITAM PUTIH seluruhnya (printer kantor lazimnya
    monokrom), tata letak memakai tabel karena dompdf tidak mendukung flexbox
    maupun grid, dan JENIS HURUFNYA satu macam — Helvetica, persis yang dipakai
    biling. Jangan menambah font-family lain di sini: dokumen Nafsul harus
    terlihat berasal dari satu aplikasi yang sama, dan alasan teknis memilih
    font inti PDF (bukan DejaVu) ada di catatan huruf pdf.nafsul_biling —
    singkatnya: font yang ditanam ke berkas keluar terpotong di printer dot
    matrix LX.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kuitansi Jasa{{ count($lembar) === 1 ? ' '.$lembar[0]['header']->transaction_number : '' }}</title>
    <style>
        /* Margin halaman nol: jarak tepi diatur per LAJUR, supaya garis potong
           jatuh tepat di tengah tinggi kertas (105 mm dari atas). */
        @page { margin: 0; }

        /*
            SATU JENIS HURUF, SATU UKURAN, SEMUANYA TEBAL — aturan yang sama
            dengan pdf.nafsul_biling, termasuk alasannya: huruf bergaris tipis
            keluar putus-putus dari jarum printer LX begitu pitanya mulai
            kering. Yang boleh berbeda hanya ukuran judul: nama rumah sakit di
            kop dan kata KUITANSI.
        */
        body {
            font-family: Helvetica;
            font-size: 16px;
            font-weight: bold;
            color: #000;
            margin: 0;
        }

        /* Dua lajur per halaman. `page-break-after` dipasang pada SETIAP
           halaman kecuali yang terakhir — memasangnya di semua halaman
           menyisakan satu halaman kosong di ujung berkas. */
        .halaman { page-break-inside: avoid; }
        .halaman.putus { page-break-after: always; }

        /* Satu kuitansi = setengah tinggi A4 melintang.
           dompdf menghitung `height` TANPA padding (content-box): 90 mm + 2 × 7 mm
           = 104 mm. Sengaja 1 mm di bawah 105 mm — pembulatan yang melebihi
           tinggi kertas mendorong lajur bawah ke halaman berikutnya sendirian.
           `overflow: hidden` menjaga nama/terbilang yang sangat panjang tidak
           menjebol lajurnya. */
        .lajur {
            height: 90mm;
            padding: 7mm 22mm;
            overflow: hidden;
        }
        /* Garis potong: putus-putus, di bawah lajur ATAS. Tetap tercetak pada
           kuitansi satuan, supaya lembarnya dipotong dengan ukuran yang sama
           dengan hasil cetak massal. */
        .lajur.atas { border-bottom: 1px dashed #000; }

        /* Kop surat: ukuran & spasinya disamakan dengan pdf.nafsul_biling;
           tata letak logonya di pdf.partials.kop_nafsul_style. */
        .kop-nama {
            font-size: 20px;
            letter-spacing: .3px;
        }
        .kop-unit {
            letter-spacing: .3px;
            margin-top: 1px;
        }
        .kop-alamat { margin-top: 1px; }

        .garis { border-bottom: 1.5px solid #000; margin: 5px 0 8px; }

        .judul {
            text-align: center;
            font-size: 20px;
            letter-spacing: .4px;
            margin-bottom: 8px;
        }

        /* Badan kuitansi: isian di KIRI, tanda tangan di KANAN, sejajar.
           Ditumpuk seperti lembar sehalaman dulu, tingginya tidak muat di
           setengah kertas. */
        table.badan { width: 100%; border-collapse: collapse; }
        table.badan td.badan-kiri { width: 70%; vertical-align: top; padding-right: 8mm; }
        table.badan td.badan-kanan { width: 30%; vertical-align: top; }

        /* Isi kuitansi ikut ukuran badan lembar ini (16px) — di dalam lembarnya
           sendiri tidak ada dua ukuran yang berbeda. Jarak antarbaris dibuat
           rapat supaya lima baris muat di setengah kertas. */
        table.isi { width: 100%; border-collapse: collapse; }
        table.isi td { padding: 4px 0; vertical-align: top; }
        table.isi td.label { width: 30%; white-space: nowrap; }
        table.isi td.pemisah { width: 3%; }

        /* Nilai yang diisi: bergaris bawah putus supaya terbaca sebagai isian
           lembar, bukan sebagai kalimat biasa. */
        .isian { border-bottom: 1px dotted #000; display: block; padding-bottom: 2px; }

        /* Blok tanda tangan: "Tgl Cetak" dan "Penerima" rata kiri di dalam
           kotak yang sama; nama penerima rata tengah di atas garisnya. */
        .blok-ttd { padding-top: 4px; }
        /* Ruang tanda tangan basah antara "Penerima" dan nama penerimanya. */
        .ruang-ttd { height: 62px; }
        /* `display: block` agar garis atasnya memenuhi lebar kotak, bukan cuma
           selebar namanya. */
        .nama-ttd {
            border-top: 1px solid #000;
            padding-top: 4px;
            display: block;
            text-align: center;
        }
    </style>
    @include('pdf.partials.kop_nafsul_style')
</head>
<body>

@foreach (array_chunk($lembar, 2) as $isiHalaman)
<div class="halaman {{ $loop->last ? '' : 'putus' }}">

    @foreach ($isiHalaman as $l)
    <div class="lajur {{ $loop->first ? 'atas' : '' }}">

        @include('pdf.partials.kop_nafsul')

        <div class="garis"></div>

        <div class="judul">KUITANSI</div>

        <table class="badan">
            <tr>
                <td class="badan-kiri">
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
                </td>
                <td class="badan-kanan">
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

</div>
@endforeach

</body>
</html>
