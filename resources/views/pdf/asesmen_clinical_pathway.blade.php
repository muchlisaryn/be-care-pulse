<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Asesmen Clinical Pathway</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
        h1 { font-size: 15px; margin: 0 0 2px; color: #075489; }
        .muted { color: #6b7280; }
        .sub { font-size: 10px; color: #4b5563; margin: 0 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 4px; vertical-align: top; }
        .info .lbl { color: #6b7280; width: 110px; }
        .section-title {
            background: #075489; color: #fff; padding: 4px 6px; font-weight: bold;
            font-size: 11px; margin-top: 12px;
        }
        .grid { border: 1px solid #d1d5db; margin-top: 0; }
        .grid th, .grid td { border: 1px solid #d1d5db; padding: 3px 4px; }
        .grid th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
        .grid td.center, .grid th.center { text-align: center; }
        .day-col { width: 18px; }
        .pengisi { font-size: 8px; color: #374151; }
        .group td { background: #f9fafb; font-weight: bold; }
        .check { color: #047857; font-weight: bold; font-size: 13px; }
        .badge {
            display: inline-block; padding: 0 4px; border-radius: 6px; font-size: 8px;
            border: 1px solid #d1d5db;
        }
        .verif-box { border: 1px solid #d1d5db; padding: 8px; height: 130px; vertical-align: top; text-align: center; }
        .verif-caption { margin-top: 4px; font-size: 9px; }
        .small { font-size: 9px; }
    </style>
</head>
<body>
    @php
        $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-m-Y') : '—';
        $fmtDateTime = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-m-Y H:i') : '—';
        $jk = $asesmen->jenis_kelamin === 'L' ? 'Laki-laki' : ($asesmen->jenis_kelamin === 'P' ? 'Perempuan' : '—');
    @endphp

    <h1>FORMULIR CLINICAL PATHWAY</h1>
    <p class="sub">
        @if($template?->icd10)
            {{ $template->icd10->code }} — {{ $template->icd10->display }}
        @endif
        · Maksimal {{ $maxHari }} hari
    </p>

    <table class="info">
        <tr>
            <td class="lbl">No. RM</td><td>: {{ $asesmen->no_rm ?: '—' }}</td>
            <td class="lbl">Diagnosa Masuk</td><td>: {{ $asesmen->diagnosa_masuk ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Nama Pasien</td><td>: {{ $asesmen->nama_pasien ?: '—' }}</td>
            <td class="lbl">Penyakit Utama</td><td>: {{ $asesmen->penyakit_utama ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Jenis Kelamin</td><td>: {{ $jk }}</td>
            <td class="lbl">Penyakit Penyerta</td><td>: {{ $asesmen->penyakit_penyerta ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Tanggal Lahir</td><td>: {{ $fmtDate($asesmen->tanggal_lahir) }}</td>
            <td class="lbl">Komplikasi</td><td>: {{ $asesmen->komplikasi ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Ruang Rawat</td><td>: {{ $asesmen->ruang->name ?? '—' }}</td>
            <td class="lbl">Tindakan</td><td>: {{ $asesmen->tindakan ?: '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Kelas</td><td>: {{ $asesmen->kelas ?: '—' }}</td>
            <td class="lbl">BB / TB</td><td>: {{ $asesmen->bb ?? '—' }} kg / {{ $asesmen->tb ?? '—' }} cm</td>
        </tr>
        <tr>
            <td class="lbl">Tgl/Jam Masuk</td><td>: {{ $fmtDateTime($asesmen->tanggal_jam_masuk) }}</td>
            <td class="lbl">Lama Rawat</td><td>: {{ $asesmen->lama_rawat !== null ? $asesmen->lama_rawat.' hari' : '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Tgl/Jam Keluar</td><td>: {{ $fmtDateTime($asesmen->tanggal_jam_keluar) }}</td>
            <td class="lbl">Rujukan</td><td>: {{ $asesmen->rujukan ? 'Ya' : 'Tidak' }}</td>
        </tr>
    </table>

    @forelse($sections as $section)
        <div class="section-title">{{ $section['urutan'] }}. {{ $section['label'] }}</div>
        <table class="grid">
            <thead>
                <tr>
                    <th style="width:34px">No</th>
                    <th>Poin</th>
                    @foreach($days as $d)
                        <th class="center day-col">{{ $d }}</th>
                    @endforeach
                    <th style="width:120px">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($section['rows'] as $row)
                    <tr class="{{ $row['hasChildren'] ? 'group' : '' }}">
                        <td>{{ $row['number'] }}</td>
                        <td style="padding-left: {{ 4 + $row['depth'] * 12 }}px">
                            {{ $row['label'] }}
                        </td>
                        @foreach($days as $d)
                            <td class="center">
                                @if(!$row['hasChildren'] && in_array($d, $row['checked']))<span class="check">&#10004;</span>@endif
                            </td>
                        @endforeach
                        <td class="small">{{ $row['hasChildren'] ? '' : ($row['keterangan'] ?: '') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="muted" style="margin-top:12px">Formulir belum memiliki poin.</p>
    @endforelse

    <div class="section-title">Pencatatan Varian</div>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:120px">Tanggal &amp; Waktu</th>
                <th>Varian yang Terjadi</th>
                <th>Alasan Varian Terjadi</th>
                <th style="width:90px">Paraf</th>
            </tr>
        </thead>
        <tbody>
            @forelse($varians as $v)
                <tr>
                    <td>{{ $fmtDateTime($v->tanggal_waktu) }}</td>
                    <td>{{ $v->varian }}</td>
                    <td>{{ $v->alasan ?: '—' }}</td>
                    <td>{{ $v->paraf }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="center muted">Belum ada catatan varian.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">Verifikasi</div>
    <table style="margin-top:0">
        <tr>
            @foreach($verifs as $v)
                <td class="verif-box" style="width:33.33%; text-align:center;">
                    <div style="font-weight:bold;">{{ $v['title'] }}</div>
                    @if($v['at'])
                        <div style="margin-top:6px;">
                            <img src="{{ $v['qr'] }}" style="width:70px; height:70px;" alt="QR Verifikasi">
                        </div>
                        <div class="verif-caption">
                            Sudah diverifikasi oleh<br>
                            <strong>{{ $v['by'] }}</strong>
                        </div>
                        <div class="small muted">{{ $fmtDateTime($v['at']) }}</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>
</body>
</html>
