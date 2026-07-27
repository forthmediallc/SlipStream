{{-- Rendered inside each Livewire component so action feedback shows on
     Livewire (AJAX) responses, which never re-render the page layout. --}}
@if(session('slipstream_status'))
    <div class="ss-alert ss-alert-ok">{{ session('slipstream_status') }}</div>
@endif
@if(session('slipstream_error'))
    <div class="ss-alert ss-alert-err">{{ session('slipstream_error') }}</div>
@endif
