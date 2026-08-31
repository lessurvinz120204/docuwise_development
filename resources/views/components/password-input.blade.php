{{--
    A password <input> with a leading lock icon plus its eye-icon reveal
    toggle on the right — just the input itself, not a label wrapper, so
    each caller keeps its own existing label markup (e.g. login.blade.php's
    label + "Forgot password?" link sitting side by side). The actual
    show/hide behavior is one delegated listener in app.js
    (data-toggle-password="<id>"), not per-instance JS, so this component
    only needs to render matching markup.
--}}
@props(['id', 'name' => null])

<div class="relative">
    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-surface-400">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
        </svg>
    </span>
    <input id="{{ $id }}" name="{{ $name ?? $id }}" type="password"
        {{ $attributes->merge(['class' => 'w-full rounded-lg border-surface-300 focus:border-primary-500 focus:ring-primary-500 text-sm pl-10 pr-10 py-2.5']) }}>
    <button type="button" data-toggle-password="{{ $id }}" tabindex="-1"
        class="absolute inset-y-0 right-0 flex items-center px-3 text-surface-400 hover:text-surface-600">
        <svg data-eye-open class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <svg data-eye-closed class="w-4 h-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
    </button>
</div>
