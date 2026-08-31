@component('mail::message')
# Your Sign-In Code

Someone (hopefully you) entered the correct password for your {{ config('app.name') }} account ({{ $user->email }}). Enter this code to finish signing in:

@component('mail::panel')
<div style="font-size: 28px; font-weight: 700; letter-spacing: 4px; text-align: center;">{{ $code }}</div>
@endcomponent

This code expires in 10 minutes. If you didn't just try to sign in, you can ignore this email — your account stays locked without it.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
