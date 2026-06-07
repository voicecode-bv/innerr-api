<x-mail::message>
# New support request

**From:** {{ $sender->name }} ({{ $sender->email }})
**App version:** {{ $appVersion ?: 'unknown' }}
**Platform:** {{ $platform ?: 'unknown' }}

---

{{ $supportMessage }}

<x-mail::button :url="'mailto:'.$sender->email">
Reply to {{ $sender->name }}
</x-mail::button>
</x-mail::message>
