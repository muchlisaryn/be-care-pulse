{{--
    Kop surat semua cetakan Nafsul: logo unit di kiri, identitas rumah sakit di
    tengah. Ukuran hurufnya (`.kop-nama`, `.kop-unit`, `.kop-alamat`) tetap
    milik lembar masing-masing — biling dan kuitansi jasa memakai skala yang
    berbeda — sedangkan tata letak logonya ada di `pdf.partials.kop_nafsul_style`.

    Logo dimuat lewat PATH LOKAL, bukan data URI seperti QR di lembar biling.
    `chroot` dompdf adalah base_path(), jadi berkas di dalam public/ boleh
    dibaca; data URI hanya perlu untuk gambar yang memang tidak punya berkas.
--}}
<table class="kop">
    <tr>
        <td class="kop-logo">
            <img src="{{ public_path('images/logo_nafsul.png') }}" alt="Nafsul Mutmainnah">
        </td>
        <td class="kop-teks">
            <div class="kop-nama">RUMAH SAKIT ISLAM JAKARTA PONDOK KOPI</div>
            <div class="kop-unit">UNIT LAYANAN NAFSUL MUTMAINAH</div>
            <div class="kop-alamat">Jl. Raya Pondok Kopi - Jakarta Timur 13460</div>
            <div class="kop-alamat">tlp. 021--61-471, 0630654 ext 5111 fax 021-0611101</div>
        </td>
        <td class="kop-sisi"></td>
    </tr>
</table>
