<h1>{{ $kind }}</h1>
<p><strong>Name:</strong> {{ $inquiry['name'] }}</p>
<p><strong>Email:</strong> {{ $inquiry['email'] }}</p>
@if (! empty($inquiry['phone']))
    <p><strong>Phone:</strong> {{ $inquiry['phone'] }}</p>
@endif
@if (! empty($inquiry['school']))
    <p><strong>School:</strong> {{ $inquiry['school'] }}</p>
@endif
@if (! empty($inquiry['role']))
    <p><strong>Role:</strong> {{ $inquiry['role'] }}</p>
@endif
<h2>Message</h2>
<p>{!! nl2br(e($inquiry['message'] ?? 'No additional details provided.')) !!}</p>