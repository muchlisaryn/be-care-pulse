{{--
    Biling kuitansi iuran Nafsul.

    Dicetak hanya untuk kuitansi yang SUDAH divalidasi — lihat
    TransaksiHeaderController::biling(). Tata letaknya sengaja satu kolom penuh
    tanpa grid CSS modern: dompdf tidak mendukung flexbox maupun grid, jadi
    penjajaran memakai tabel, seperti pdf.asesmen_clinical_pathway.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Biling {{ $header->transaction_number }}</title>
    <style>
        /*
            Lembar ini HITAM PUTIH seluruhnya — tidak ada satu pun warna, juga
            tidak ada latar abu-abu.

            Bukan pilihan gaya: biling dicetak di printer kantor yang lazimnya
            monokrom, dan pembeda yang mengandalkan warna (baris berselang-seling,
            penanda B/L berwarna, angka merah untuk selisih) sama sekali tidak
            terbaca begitu tintanya cuma hitam — yang tersisa justru abu-abu yang
            membuat teks di atasnya lebih sulit dibaca daripada tanpa latar.

            Susunannya karena itu dipisah oleh UKURAN, TEBAL HURUF, dan GARIS,
            bukan warna. Setiap kali menambah aturan di bawah ini, pakai ketiganya
            saja.
        */
        @page { margin: 18mm 16mm; }

        /*
            HURUFNYA HELVETICA & COURIER, bukan DejaVu, dan ukurannya jauh lebih
            besar dari sebelumnya. Keduanya karena lembar ini dicetak di printer
            dot matrix Epson LX.

            DejaVu ditanam (embedded) ke dalam PDF sebagai subset TrueType, dan
            di jalur cetak LX subset itulah yang bikin huruf keluar terpotong —
            driver 9 jarum merasterkan halaman pada resolusi rendah, dan garis
            huruf yang lebih tipis dari satu titik jarum hilang begitu saja.
            Helvetica dan Courier adalah font INTI PDF: tidak ditanam sama
            sekali, jadi yang dipakai adalah huruf yang sudah dikenal penampil &
            printernya sendiri.

            Ukurannya naik dari 10px ke 13px dengan alasan yang sama: pada 120
            dpi vertikal, huruf 10px cuma setinggi belasan titik jarum dan
            bagian atas-bawahnya yang pertama hilang.

            KONSEKUENSI: font inti hanya mengenal huruf Windows-1252. Jangan
            memakai tanda di luar itu — tanda minus U+2212, tanda petik miring,
            panah — karena yang keluar bukan hurufnya. Untuk pengurangan pakai
            tanda hubung biasa, seperti pada blok ringkasan di bawah.
        */
        body {
            font-family: Helvetica;
            font-size: 13px;
            color: #000;
            margin: 0;
        }

        /* Kop surat: logonya di `pdf.partials.kop_nafsul_style`. */
        .kop-nama {
            font-size: 17px;
            font-weight: bold;
            letter-spacing: .3px;
        }
        .kop-unit {
            font-size: 14px;
            font-weight: bold;
            letter-spacing: .3px;
            margin-top: 1px;
        }
        .kop-alamat {
            font-size: 10.5px;
            margin-top: 1px;
        }

        .garis { border-bottom: 1.5px solid #000; margin: 6px 0 10px; }

        table { width: 100%; border-collapse: collapse; }

        .kepala td { vertical-align: top; padding: 0; }
        .kepala .kanan { text-align: right; }

        .nomor { font-weight: bold; letter-spacing: .5px; }

        .meta td { padding: 2px 0; }
        .meta .label { width: 108px; }

        /*
            Kepala tabel dulu berlatar biru pekat dengan huruf putih. Diganti
            garis atas-bawah + huruf tebal: pada cetakan hitam putih, latar pekat
            berubah jadi blok hitam yang menelan tulisannya.
        */
        .rincian { margin-top: 14px; }
        .rincian th {
            font-size: 11px;
            text-align: left;
            padding: 6px 5px;
            text-transform: uppercase;
            letter-spacing: .3px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }
        .rincian td { padding: 6px 5px; border-bottom: 1px solid #000; }

        .angka { text-align: right; white-space: nowrap; }
        .tengah { text-align: center; }
        /*
            Courier, sesama font inti — lihat catatan huruf di atas.

            `nowrap` bukan hiasan: tanpa itu rentang periode boleh patah di
            tanda hubungnya, dan dompdf yang menghitung lebar kolom dari
            potongan terpanjang lalu memberi kolom Periode dua baris —
            "01/2026-" di atas "12/2026" — padahal selisihnya cuma sepersekian
            milimeter. Dengan nowrap, lebar kolomnya dihitung dari rentang utuh.
        */
        .mono { font-family: Courier; font-size: 11px; white-space: nowrap; }

        /*
            Penanda kunjungan: huruf tebal saja, tanpa kotak berlatar. Satu huruf
            di kolomnya sendiri sudah cukup menonjol; kotak yang latarnya hilang
            saat dicetak hanya menyisakan huruf yang letaknya jadi aneh.
        */
        .tanda { font-weight: bold; }

        .keterangan { margin: 6px 0 0; font-size: 11px; }

        /* Penutup lembar: verifikasi kiri, ringkasan uang kanan. */
        .penutup { margin-top: 14px; }
        .penutup > tr > td { vertical-align: top; }
        .penutup-kiri { padding-right: 10px; }
        /* Selebar kira-kira sepertiga halaman; sisanya milik blok verifikasi. */
        .penutup-kanan { width: 304px; }

        .ringkas td { padding: 4px 6px; }
        /* `nowrap` juga di labelnya: "Seharusnya Dibayar" yang pecah dua baris
           membuat angkanya berdiri sendiri tanpa keterangan di barisnya. */
        .ringkas .label { text-align: right; white-space: nowrap; }
        .ringkas .nilai { text-align: right; white-space: nowrap; width: 140px; }
        .ringkas .tebal td { font-weight: bold; border-top: 1px solid #000; }
        .ringkas .akhir td {
            font-weight: bold;
            font-size: 16px;
            border-top: 1.5px solid #000;
        }

        .kepala-kotak {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
        }
    </style>
    @include('pdf.partials.kop_nafsul_style')
</head>
<body>

{{--
    Kop surat unit. Nomor kuitansi & jenisnya turun ke blok keterangan di
    bawahnya — kop hanya menyebut lembaganya, dan nomor yang menempel di sana
    akan terbaca sebagai bagian dari identitas rumah sakit, bukan identitas
    lembar ini.
--}}
@include('pdf.partials.kop_nafsul')

<div class="garis"></div>

<table class="kepala">
    <tr>
        <td style="width: 55%">
            <table class="meta">
                <tr>
                    <td class="label">No. Kuitansi</td>
                    <td>: <span class="nomor">{{ $header->transaction_number }}</span></td>
                </tr>
                <tr>
                    <td class="label">Tanggal</td>
                    <td>: {{ $tanggal }}</td>
                </tr>
            </table>
        </td>
        <td style="width: 45%">
            <table class="meta">
                <tr>
                    <td class="label">Jenis</td>
                    <td>: {{ ucfirst($header->transaction_type) }} - {{ ucfirst($header->payment_method) }}</td>
                </tr>
                <tr>
                    <td class="label">Jumlah Anggota</td>
                    <td>: {{ count($baris) }} orang</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{--
    Satu baris per ANGGOTA, bukan per bulan.

    Anggota yang membayar setahun sekaligus muncul sekali dengan rentang
    "01/2026 – 12/2026"; dua belas baris berisi tarif dan nominal yang sama
    persis tidak menambah apa pun bagi yang memegang lembar ini. Rinciannya
    tetap bisa ditelusuri di aplikasi.
--}}
<table class="rincian">
    <thead>
        <tr>
            {{--
                Lebar kolom dihitung ulang mengikuti huruf yang membesar: yang
                isinya tetap (nomor, periode, rupiah) dipatok pas untuk isi
                terpanjangnya, sisanya milik kolom nama. Dibiarkan seperti
                ukuran lama, angka rupiah tujuh digit pecah jadi dua baris.
            --}}
            <th style="width: 26px">No</th>
            <th style="width: 78px">No Anggota</th>
            <th>Nama Anggota</th>
            <th style="width: 96px" class="tengah">Periode</th>
            {{--
                Kepalanya "B/L", bukan "Kunjungan": kata itu tiga kali lebih
                lebar dari isinya yang cuma satu huruf, dan lebar yang
                dimakannya diambil dari kolom nama — nama panjang lalu pecah
                jadi tiga baris. Artinya tetap terbaca dari keterangan di bawah
                tabel.
            --}}
            <th style="width: 34px" class="tengah">B/L</th>
            <th style="width: 92px" class="angka">Jumlah</th>
            <th style="width: 84px" class="angka">Pot Anggota</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($baris as $i => $b)
            <tr>
                <td class="tengah">{{ $i + 1 }}</td>
                <td class="mono">{{ $b['no_anggota'] ?? '-' }}</td>
                <td>{{ $b['nama'] }}</td>
                <td class="tengah mono">{{ $b['periode'] }}</td>
                {{-- L = sudah pernah beriuran sebelum kuitansi ini, B = baru mulai di sini. --}}
                <td class="tengah">
                    <span class="tanda">
                        {{ $b['kunjungan'] }}
                    </span>
                </td>
                <td class="angka">{{ $b['jumlah'] }}</td>
                <td class="angka">{{ $b['potongan'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="keterangan">
    Kunjungan: <b>B</b> = baru, <b>L</b> = lama.
</p>

{{--
    Penutup lembar: kotak verifikasi di KIRI, ringkasan uang di KANAN, sejajar.

    Ditumpuk seperti sebelumnya, keduanya saling mendorong turun dan pada
    kuitansi berbaris banyak ringkasan uangnya bisa terlempar ke halaman
    berikutnya sendirian — angka yang paling dicari justru yang paling jauh dari
    tabelnya. Bersebelahan, keduanya selalu terbaca dalam satu pandangan.
--}}
<table class="penutup">
    <tr>
        <td class="penutup-kiri">
            <table>
                <tr>
                    {{--
                        Lebar sel QR dipatok, bukan dibiarkan mengikuti isi:
                        pada kuitansi yang belum punya QR, sel kosong yang
                        menyusut membuat keterangan di sebelahnya bergeser ke
                        kiri dan kotaknya terlihat berbeda dari lembar lain.
                    --}}
                    <td style="width: 84px" class="tengah">
                        {{--
                            Di-embed sebagai data URI karena dompdf tidak bisa
                            memuat berkas dari luar dokumen.
                        --}}
                        @if ($qr)
                            <img src="{{ $qr }}" alt="QR verifikasi" style="width:74px;height:74px">
                        @endif
                    </td>
                    <td style="vertical-align: middle">
                        <div class="kepala-kotak">Diverifikasi Oleh</div>
                        <div style="font-weight:bold">{{ $header->validation_by ?: '-' }}</div>
                        <div>{{ $divalidasi }}</div>
                    </td>
                </tr>
            </table>
        </td>

        <td class="penutup-kanan">
            <table class="ringkas">
                <tr>
                    <td class="label">Total Rincian</td>
                    <td class="nilai">{{ $uang['total'] }}</td>
                </tr>
                @if ($header->member_deduction > 0)
                    <tr>
                        <td class="label">Potongan Anggota</td>
                        <td class="nilai">- {{ $uang['member_deduction'] }}</td>
                    </tr>
                @endif
                @if ($header->group_leader_deduction > 0)
                    <tr>
                        <td class="label">
                            Potongan Ketua
                            @if ($header->group_leader_fee_percent > 0)
                                ({{ rtrim(rtrim(number_format((float) $header->group_leader_fee_percent, 2, ',', '.'), '0'), ',') }}%)
                            @endif
                        </td>
                        <td class="nilai">- {{ $uang['group_leader_deduction'] }}</td>
                    </tr>
                @endif
                <tr class="tebal">
                    <td class="label">Seharusnya Dibayar</td>
                    <td class="nilai">{{ $uang['tagihan'] }}</td>
                </tr>
                <tr class="akhir">
                    <td class="label">Dibayar</td>
                    <td class="nilai">{{ $uang['payment'] }}</td>
                </tr>
                {{--
                    Baris selisih hanya muncul bila ADA selisihnya. Pada kuitansi
                    yang lunas, "Kurang Bayar Rp 0" cuma menimbulkan keraguan
                    terhadap lembar yang sebenarnya beres.
                --}}
                @if ($selisih['nilai'] != 0)
                    <tr>
                        <td class="label" style="font-weight:bold">{{ $selisih['label'] }}</td>
                        <td class="nilai" style="font-weight:bold">
                            {{ $selisih['rupiah'] }}
                        </td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

</body>
</html>
