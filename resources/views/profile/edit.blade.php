@extends('layouts.app')

@section('title', 'My Profile — Humphrey Building Materials')

@section('content')
<section class="page-banner">
    <div class="container">
        <h1>My Profile</h1>
        <p>Manage your personal information.</p>
    </div>
</section>

<section class="section">
    <div class="container auth-wrap">
        <div class="auth-card auth-card-wide">

            @include('profile.partials.nav')

            <h2>Profile Details</h2>

            {{-- Profile picture (optional — §31) --}}
            <div class="form-group" style="display:flex; align-items:center; gap:16px;">
                @if ($user->avatarUrl())
                    <img src="{{ $user->avatarUrl() }}" alt="Profile picture"
                         style="width:72px; height:72px; border-radius:50%; object-fit:cover;">
                @else
                    <div style="width:72px; height:72px; border-radius:50%; background:#e8eef7;
                                display:flex; align-items:center; justify-content:center; font-size:1.6rem;">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                @endif
                <div>
                    <strong>Profile picture</strong>
                    <br><small class="empty-inline">JPG, PNG or WebP — up to 2 MB. Optional.</small>
                    @if ($user->avatarUrl())
                        <form action="{{ route('profile.avatar.remove') }}" method="POST" style="margin-top:6px;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-dark">Remove picture</button>
                        </form>
                    @endif
                </div>
            </div>

            <form action="{{ route('profile.update') }}" method="POST" novalidate enctype="multipart/form-data">
                @csrf
                @method('PATCH')

                <div class="form-group">
                    <label for="avatar">Change Profile Picture <span class="optional">(optional)</span></label>
                    <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp"
                           class="{{ $errors->has('avatar') ? 'is-invalid' : '' }}">
                    @error('avatar')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="name">Full Name <span class="req">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                           class="{{ $errors->has('name') ? 'is-invalid' : '' }}">
                    @error('name')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="email">Email Address <span class="req">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                           class="{{ $errors->has('email') ? 'is-invalid' : '' }}">
                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    @if ($user->hasVerifiedEmail())
                        <small class="field-help verified">✓ Email verified</small>
                    @else
                        <small class="field-help unverified">⚠ Email not verified yet</small>
                    @endif
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="{{ old('phone', $user->phone) }}"
                           placeholder="+2348153667923"
                           class="{{ $errors->has('phone') ? 'is-invalid' : '' }}">
                    @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="3"
                              placeholder="Street, city, state..."
                              class="{{ $errors->has('address') ? 'is-invalid' : '' }}">{{ old('address', $user->address) }}</textarea>
                    @error('address')<span class="field-error">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label>Role</label>
                    <input type="text" value="{{ $user->roleLabel() }}" readonly class="readonly">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
            </form>
        </div>
    </div>
</section>
@endsection
