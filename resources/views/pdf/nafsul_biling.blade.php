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

            Susunannya karena itu dipisah oleh GARIS dan HURUF KAPITAL, bukan
            warna — dan bukan pula ukuran maupun tebal huruf, yang keduanya
            sudah dipakai habis: lihat catatan huruf di bawah. Setiap kali
            menambah aturan di bawah ini, pakai keduanya saja.
        */
        /*
            Margin samping DISEMPITKAN dari 16mm ke 12mm saat huruf dibesarkan.
            Tujuh kolom berhuruf 15px tidak muat di lebar sisa margin lama:
            yang mengalah selalu kolom nama, dan nama anggota yang pecah dua
            baris membuat tabelnya terbaca dua kali lebih panjang dari isinya.
            12mm masih di dalam batas cetak lazim printer A4 mana pun.
        */
        @page { margin: 14mm 12mm; }

        /*
            SATU JENIS HURUF, SATU UKURAN, untuk seluruh isi lembar: Helvetica
            15px. Yang boleh berbeda hanya nama rumah sakit di kop — itu kepala
            surat, bukan isi.

            Sebelumnya lembar ini memakai lima ukuran sekaligus (8px keterangan,
            9px kolom bernomor, 10px isi, 12px angka akhir, 14px kop) dan dua
            jenis huruf. Di layar bedanya terlihat sebagai hierarki; di kertas
            hasil printer 9 jarum bedanya cuma terbaca sebagai cetakan yang tidak
            rapi — dan yang paling kecil justru hilang lebih dulu. Penekanan di
            sini karena itu dipisah GARIS dan HURUF KAPITAL saja.

            HELVETICA, bukan DejaVu, dan bukan Courier untuk kolom bernomor.
            DejaVu ditanam (embedded) ke dalam PDF sebagai subset TrueType, dan
            di jalur cetak LX subset itulah yang bikin huruf keluar terpotong —
            driver 9 jarum merasterkan halaman pada resolusi rendah, dan garis
            huruf yang lebih tipis dari satu titik jarum hilang begitu saja.
            Helvetica adalah font INTI PDF: tidak ditanam sama sekali, jadi yang
            dipakai adalah huruf yang sudah dikenal penampil & printernya
            sendiri. Angkanya pun tetap berjajar rapi — pada font inti semua
            digit sama lebar, jadi kolom rupiah tidak butuh huruf monospace.

            Ukurannya naik dari 10px ke 15px dengan alasan yang sama: pada 120
            dpi vertikal, huruf 10px cuma setinggi belasan titik jarum dan
            bagian atas-bawahnya yang pertama hilang.

            KONSEKUENSI: font inti hanya mengenal huruf Windows-1252. Jangan
            memakai tanda di luar itu — tanda minus U+2212, tanda petik miring,
            panah — karena yang keluar bukan hurufnya. Untuk pengurangan pakai
            tanda hubung biasa, seperti pada blok ringkasan di bawah.
        */
        /*
            SELURUH LEMBAR DICETAK TEBAL.

            Bukan penekanan, melainkan syarat supaya terbaca: jarum printer LX
            mencetak dengan menumbuk pita, dan garis huruf setipis satu titik
            jarum keluar putus-putus atau tidak keluar sama sekali begitu
            pitanya mulai kering — persis keluhan "hurufnya kepotong". Huruf
            tebal digambar dengan garis lebih lebar dari satu titik, jadi apa
            pun keadaan pitanya ia tetap utuh.

            Helvetica-Bold juga font INTI PDF, sama seperti Helvetica biasa, jadi
            ini tidak menanam apa pun ke dalam berkasnya.

            AKIBATNYA: tebal huruf tidak bisa lagi dipakai membedakan apa pun di
            lembar ini. Kepala tabel, baris "Dibayar", dan nomor kuitansi
            dibedakan HURUF KAPITAL dan GARIS saja.
        */
        body {
            font-family: Helvetica;
            font-size: 15px;
            font-weight: bold;
            color: #000;
            margin: 0;
        }

        /*
            Kop surat: SATU-SATUNYA tempat ukuran huruf boleh berbeda dari isi
            lembar, dan hanya pada nama rumah sakitnya. Unit & alamat seukuran
            isi. Tata letak logonya di `pdf.partials.kop_nafsul_style`.
        */
        .kop-nama {
            font-size: 20px;
            letter-spacing: .3px;
        }
        .kop-unit {
            letter-spacing: .3px;
            margin-top: 1px;
        }
        .kop-alamat { margin-top: 1px; }

        .garis { border-bottom: 1.5px solid #000; margin: 6px 0 10px; }

        table { width: 100%; border-collapse: collapse; }

        .kepala td { vertical-align: top; padding: 0; }
        .kepala .kanan { text-align: right; }

        /* Nomor kuitansi: dirapatkan-jarangkan hurufnya supaya terbaca sebagai
           satu kode, bukan deret angka biasa. */
        .nomor { letter-spacing: .5px; }

        .meta td { padding: 2px 0; }
        .meta .label { width: 130px; }

        .rincian { margin-top: 14px; }
        /*
            Kepala tabel dulu berlatar biru pekat dengan huruf putih: pada
            cetakan hitam putih latar pekat berubah jadi blok hitam yang menelan
            tulisannya. Sekarang ia SEUKURAN dan SETEBAL isi tabelnya, dan yang
            membedakannya huruf kapital + garis atas-bawah.
        */
        .rincian th {
            text-align: left;
            padding: 6px 5px;
            text-transform: uppercase;
            letter-spacing: .3px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }
        .rincian td { padding: 6px 5px; border-bottom: 1px solid #000; }

        .angka { text-align: right; }
        .tengah { text-align: center; }
        /*
            Yang tidak boleh patah barisnya hanya ISINYA — rupiah yang pecah
            jadi "Rp 1.250." di atas "000" tidak bisa dibaca sebagai angka.
            KEPALA kolomnya justru harus boleh patah: "POT ANGGOTA" yang
            dipaksa satu baris memakan lebar dua kali isinya, dan yang
            membayarnya adalah kolom nama di sebelahnya.
        */
        td.angka, td.tengah { white-space: nowrap; }
        /*
            Kolom bernomor: HURUF YANG SAMA dengan sisa lembar, hanya tidak
            boleh patah barisnya.

            Dulu Courier 11px, dan itu terlihat persis seperti yang tidak
            diinginkan — nomor anggota dan periode tampil lebih kecil dan
            berbeda bentuk dari nama di sebelahnya, dalam satu baris tabel yang
            sama. Kerapian angkanya tidak hilang: pada font inti PDF semua digit
            sama lebar, jadi kolomnya tetap berjajar tanpa huruf monospace.

            `nowrap` bukan hiasan: tanpa itu rentang periode boleh patah di
            tanda hubungnya, dan dompdf yang menghitung lebar kolom dari
            potongan terpanjang lalu memberi kolom Periode dua baris —
            "01/2026-" di atas "12/2026" — padahal selisihnya cuma sepersekian
            milimeter. Dengan nowrap, lebar kolomnya dihitung dari rentang utuh.
        */
        .mono { white-space: nowrap; }

        .keterangan { margin: 6px 0 0; }

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
        /* Dua baris ringkasan yang paling dicari dibedakan GARIS saja: satu
           garis tipis di atas "Seharusnya Dibayar", satu garis tebal di atas
           "Dibayar". Ukuran dan tebal hurufnya sudah sama dengan sisa lembar. */
        .ringkas .tebal td { border-top: 1px solid #000; }
        .ringkas .akhir td { border-top: 1.5px solid #000; }

        .kepala-kotak {
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
            <th style="width: 20px">No</th>
            <th style="width: 78px">No Anggota</th>
            <th>Nama Anggota</th>
            <th style="width: 122px" class="tengah">Periode</th>
            {{--
                Kepalanya "B/L", bukan "Kunjungan": kata itu tiga kali lebih
                lebar dari isinya yang cuma satu huruf, dan lebar yang
                dimakannya diambil dari kolom nama — nama panjang lalu pecah
                jadi tiga baris. Artinya tetap terbaca dari keterangan di bawah
                tabel.
            --}}
            <th style="width: 28px" class="tengah">B/L</th>
            <th style="width: 94px" class="angka">Jumlah</th>
            <th style="width: 82px" class="angka">Pot Anggota</th>
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
                    {{ $b['kunjungan'] }}
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
                        <div>{{ $header->validation_by ?: '-' }}</div>
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
                        <td class="label">{{ $selisih['label'] }}</td>
                        <td class="nilai">
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
