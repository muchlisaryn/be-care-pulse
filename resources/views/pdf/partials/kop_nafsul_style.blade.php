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
        Lebar sel logo DIPATOK, dan sel kanan dibuat selebar itu juga.

        Tanpa sel penyeimbang di kanan, blok tengah menempati sisa lebar
        halaman dan "rata tengah"-nya jadi tengah dari sisa itu — tergeser ke
        kanan sebesar logonya, yang langsung terlihat begitu lembarnya
        ditumpuk dengan lembar lain.
    */
    .kop-logo, .kop-sisi { width: 74px; }
    .kop-logo img { width: 66px; height: 66px; }

    .kop-teks { text-align: center; }
</style>
