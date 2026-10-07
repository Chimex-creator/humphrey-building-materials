<nav class="account-nav">
    <a href="{{ route('profile.edit') }}" class="{{ request()->routeIs('profile.edit') ? 'active' : '' }}">My Profile</a>
    <a href="{{ route('password.change') }}" class="{{ request()->routeIs('password.change*') ? 'active' : '' }}">Change Password</a>
</nav>
