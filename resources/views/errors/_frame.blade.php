<div class="container" style="padding-top:56px;padding-bottom:56px;">
    <div class="empty-state">
        <div style="font-size:3rem;font-weight:800;color:var(--navy);line-height:1;letter-spacing:-0.02em;">{{ $code }}</div>
        <h3>{{ $heading }}</h3>
        <p style="max-width:520px;margin-left:auto;margin-right:auto;">{{ $message }}</p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <a href="{{ url('/') }}" class="btn btn-primary">Back to home</a>
            <button type="button" class="btn btn-outline" onclick="history.back()">Go back</button>
        </div>
    </div>
</div>
