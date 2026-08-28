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

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #000;
            margin: 0;
        }

        /* Kop surat: rata tengah, memenuhi lebar halaman. */
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
        .kop-alamat {
            font-size: 8.5px;
            margin-top: 1px;
        }

        .garis { border-bottom: 1.5px solid #000; margin: 6px 0 10px; }

        table { width: 100%; border-collapse: collapse; }

        .kepala td { vertical-align: top; padding: 0; }
        .kepala .kanan { text-align: right; }

        .nomor { font-weight: bold; letter-spacing: .5px; }

        .meta td { padding: 1.5px 0; }
        .meta .label { width: 90px; }

        /*
            Kepala tabel dulu berlatar biru pekat dengan huruf putih. Diganti
            garis atas-bawah + huruf tebal: pada cetakan hitam putih, latar pekat
            berubah jadi blok hitam yang menelan tulisannya.
        */
        .rincian { margin-top: 14px; }
        .rincian th {
            font-size: 9px;
            text-align: left;
            padding: 5px 6px;
            text-transform: uppercase;
            letter-spacing: .4px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }
        .rincian td { padding: 5px 6px; border-bottom: 1px solid #000; }

        .angka { text-align: right; white-space: nowrap; }
        .tengah { text-align: center; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 9px; }

        /*
            Penanda kunjungan: huruf tebal saja, tanpa kotak berlatar. Satu huruf
            di kolomnya sendiri sudah cukup menonjol; kotak yang latarnya hilang
            saat dicetak hanya menyisakan huruf yang letaknya jadi aneh.
        */
        .tanda { font-weight: bold; }

        .keterangan { margin: 6px 0 0; font-size: 8px; }

        /* Penutup lembar: verifikasi kiri, ringkasan uang kanan. */
        .penutup { margin-top: 14px; }
        .penutup > tr > td { vertical-align: top; }
        .penutup-kiri { padding-right: 10px; }
        /* Selebar kira-kira sepertiga halaman; sisanya milik blok verifikasi. */
        .penutup-kanan { width: 218px; }

        .ringkas td { padding: 3px 6px; }
        .ringkas .label { text-align: right; }
        .ringkas .nilai { text-align: right; white-space: nowrap; width: 110px; }
        .ringkas .tebal td { font-weight: bold; border-top: 1px solid #000; }
        .ringkas .akhir td {
            font-weight: bold;
            font-size: 12px;
            border-top: 1.5px solid #000;
        }

        .kepala-kotak {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 4px;
        }
    </style>
</head>
<body>

{{--
    Kop surat unit. Nomor kuitansi & jenisnya turun ke blok keterangan di
    bawahnya — kop hanya menyebut lembaganya, dan nomor yang menempel di sana
    akan terbaca sebagai bagian dari identitas rumah sakit, bukan identitas
    lembar ini.
--}}
<div class="kop">
    <div class="kop-nama">RUMAH SAKIT ISLAM JAKARTA PONDOK KOPI</div>
    <div class="kop-unit">UNIT LAYANAN NAFSUL MUTMAINAH</div>
    <div class="kop-alamat">Jl. Raya Pondok Kopi - Jakarta Timur 13460</div>
    <div class="kop-alamat">tlp. 021--61-471, 0630654 ext 5111 fax 021-0611101</div>
</div>

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
                    <td>: {{ ucfirst($header->transaction_type) }} · {{ ucfirst($header->payment_method) }}</td>
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
            <th style="width: 24px">No</th>
            <th style="width: 74px">No Anggota</th>
            <th>Nama Anggota</th>
            <th style="width: 108px" class="tengah">Periode</th>
            <th style="width: 52px" class="tengah">Kunjungan</th>
            <th style="width: 78px" class="angka">Jumlah</th>
            <th style="width: 78px" class="angka">Pot Anggota</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($baris as $i => $b)
            <tr>
                <td class="tengah">{{ $i + 1 }}</td>
                <td class="mono">{{ $b['no_anggota'] ?? '—' }}</td>
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
                        <div style="font-weight:bold">{{ $header->validation_by ?: '—' }}</div>
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
                        <td class="nilai">− {{ $uang['member_deduction'] }}</td>
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
                        <td class="nilai">− {{ $uang['group_leader_deduction'] }}</td>
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
