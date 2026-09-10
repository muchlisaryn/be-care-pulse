{{--
    Gaya kop surat Nafsul. Disertakan di dalam <head> tiap lembar cetak Nafsul,
    berpasangan dengan `pdf.partials.kop_nafsul` yang memuat isinya.

    Dipisah dari lembarnya supaya kop di biling dan di kuitansi jasa tidak bisa
    menyimpang satu sama lain: yang disalin akan berbeda begitu salah satunya
    diperbaiki, dan lembar yang keluar dari satu unit yang sama sebaiknya
    berkepala sama persis.
--}}
<style>
    /* Kop surat: logo unit di kiri, identitas rumah sakit rata tengah. */
    table.kop { width: 100%; border-collapse: collapse; }
    table.kop td { vertical-align: middle; padding: 0; }

    /*
        Lebar sel logo DIPATOK supaya tepi kiri tulisannya berhenti di tempat
        yang sama pada tiap lembar, berapa pun panjang barisnya.
    */
    .kop-logo { width: 82px; }
    .kop-logo img { width: 72px; height: 72px; }

    /*
        RATA KIRI, menempel di sebelah logonya — bukan rata tengah halaman.
        Karena itu tidak ada lagi sel penyeimbang di kanan: yang dulu
        dibutuhkan hanya untuk mengembalikan titik tengah blok tulisan setelah
        logonya mengambil ruang di kiri.
    */
    .kop-teks { text-align: left; padding-left: 4px; }
</style>
