<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #374151; }
        h1 { font-size: 20px; color: #1D4ED8; margin-bottom: 4px; }
        h2 { font-size: 14px; margin: 18px 0 6px; color: #111827; }
        .muted { color: #6B7280; }
        .header-table td { padding: 3px 0; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; }
        .badge-passed  { background: #DCFCE7; color: #166534; }
        .badge-failed  { background: #FEE2E2; color: #B91C1C; }
        .badge-skipped { background: #F3F4F6; color: #9CA3AF; }
        .badge-pending { background: #FFF8E1; color: #F57F17; }
        .area-box { border: 1px solid #E5E7EB; border-radius: 6px; padding: 10px; margin-bottom: 10px; }
        .level-block { margin: 8px 0; padding-left: 8px; border-left: 2px solid #E5E7EB; }
        .question { margin: 6px 0; padding-left: 8px; }
        .option-correct  { color: #166534; }
        .option-selected-wrong { color: #B91C1C; }
        .approval-box { border-radius: 6px; padding: 10px; margin-bottom: 16px; }
        .approval-pending  { background: #FFF8E1; border: 1px solid #FFE082; color: #7A5800; }
        .approval-approved { background: #E8F5E9; border: 1px solid #A5D6A7; color: #166534; }
        .approval-rejected { background: #FEF2F2; border: 1px solid #FCA5A5; color: #B91C1C; }
        table.header-table { width: 100%; margin-bottom: 12px; }
    </style>
</head>
<body>
<h1>Esito esame</h1>
<p class="muted">Generato il {{ now()->format('d/m/Y H:i') }}</p>

<table class="header-table">
    <tr>
        <td width="50%"><strong>Candidato:</strong> {{ $examFinished->candidate->name ?? '' }} {{ $examFinished->candidate->surname ?? '' }}</td>
        <td width="50%"><strong>Esame:</strong> {{ $examFinished->exam_name_snapshot }}</td>
    </tr>
    <tr>
        <td><strong>Inizio:</strong> {{ optional($examFinished->started_at)->format('d/m/Y H:i') ?? 'N/D' }}</td>
        <td><strong>Fine:</strong> {{ optional($examFinished->ended_at)->format('d/m/Y H:i') ?? 'N/D' }}</td>
    </tr>
    <tr>
        <td><strong>Esito:</strong> {{ $runStatusLabel }}</td>
        <td><strong>Durata totale:</strong> {{ $durationLabel }}</td>
    </tr>
</table>

@php
    $approvalClass = [
        'pending' => 'approval-pending',
        'approved' => 'approval-approved',
        'rejected' => 'approval-rejected',
    ][$examFinished->approval_status] ?? 'approval-pending';
    $approvalText = [
        'pending' => 'In attesa di convalida del deliberante',
        'approved' => 'Convalidato dal deliberante' . ($examFinished->approved_at ? ' il ' . $examFinished->approved_at->format('d/m/Y H:i') : ''),
        'rejected' => 'Non convalidato dal deliberante' . ($examFinished->approved_at ? ' il ' . $examFinished->approved_at->format('d/m/Y H:i') : ''),
    ][$examFinished->approval_status] ?? '';
@endphp

<div class="approval-box {{ $approvalClass }}">
    <strong>{{ $approvalText }}</strong>
    @if($examFinished->approval_status === 'rejected' && $examFinished->approval_note)
        <br><span>Motivazione: {{ $examFinished->approval_note }}</span>
    @endif
</div>

<h2>Riepilogo per area</h2>
@foreach($examFinished->areas as $area)
    @php
        $areaBadge = [
            'passed' => ['badge-passed', 'Superata'],
            'failed_or_skipped' => ['badge-failed', 'Non superata'],
            'not_reached' => ['badge-skipped', 'Non raggiunta'],
        ][$area->area_status] ?? ['badge-skipped', $area->area_status];
    @endphp
    <div class="area-box">
        <strong>{{ $area->area_label_snapshot }}</strong>
        <span class="badge {{ $areaBadge[0] }}">{{ $areaBadge[1] }}</span>
        @if($area->level_certified_label_snapshot)
            <span class="muted"> — Livello raggiunto: {{ $area->level_certified_label_snapshot }}</span>
        @endif
    </div>
@endforeach

<h2>Dettaglio esame</h2>
@foreach($examFinished->areas as $area)
    <h2 style="font-size: 12px;">{{ $area->area_label_snapshot }}</h2>
    @foreach($area->levels as $level)
        @php
            if ($level->is_final_incomplete_level) {
                $levelBadge = ['badge-pending', 'Interrotto'];
            } elseif ($level->passed === true) {
                $levelBadge = ['badge-passed', 'Superato'];
            } elseif ($level->passed === false) {
                $levelBadge = ['badge-failed', 'Non superato'];
            } else {
                $levelBadge = ['badge-skipped', 'Non valutabile'];
            }
        @endphp
        <div class="level-block">
            <strong>{{ $level->level_name_snapshot }}</strong>
            <span class="badge {{ $levelBadge[0] }}">{{ $levelBadge[1] }}</span>
            <span class="muted">{{ $level->correct ?? '—' }}/{{ $level->total ?? '—' }} corrette</span>

            @foreach($level->questions as $question)
                <div class="question">
                    <div>{{ $question->position }}. {{ $question->question_text_snapshot }}
                        @if(!$question->was_answered)<span class="muted"> (non risposta)</span>@endif
                    </div>
                    <ul style="margin: 2px 0;">
                        @foreach($question->options->sortBy('display_order') as $option)
                            @php
                                $optClass = '';
                                if ($option->is_correct_snapshot) $optClass = 'option-correct';
                                elseif ($option->was_selected_by_candidate) $optClass = 'option-selected-wrong';
                            @endphp
                            <li class="{{ $optClass }}">
                                {{ $option->answer_text_snapshot }}
                                @if($option->was_selected_by_candidate) (selezionata)@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endforeach
@endforeach
</body>
</html>
