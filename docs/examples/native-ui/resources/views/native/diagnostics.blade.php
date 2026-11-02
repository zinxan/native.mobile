<native:scroll-view fill>
    <native:column class="gap-4 p-5">
        <native:text class="text-2xl font-bold">Share diagnostics</native:text>
        <native:text>Optional. Send technical error and crash reports to NativeWatch to help fix this app. You can use the app with sharing off and change your choice here at any time.</native:text>
        <native:text>Read this app's privacy policy for the exact data, service operator, retention and deletion contact before choosing.</native:text>
        <native:row class="items-center justify-between gap-4">
            <native:text class="flex-1">Share diagnostics</native:text>
            <native:toggle ref="diagnostics-toggle" a11y-label="Share diagnostics" native:model="sharing" />
        </native:row>
        @if ($saveError !== '')
            <native:text ref="diagnostics-error">{{ $saveError }}</native:text>
        @elseif ($sharing)
            <native:text>Sharing is on.</native:text>
        @else
            <native:text>Sharing is off. New reports are not collected or sent.</native:text>
        @endif
    </native:column>
</native:scroll-view>
