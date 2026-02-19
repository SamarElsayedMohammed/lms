<div class="navbar-bg"></div>
<nav class="navbar navbar-expand-lg main-navbar">
    <form class="form-inline mr-auto">
        <ul class="navbar-nav mr-3">
            <li><a href="#"
                    data-toggle="sidebar"
                    class="nav-link nav-link-lg"><i class="fas fa-bars"></i></a></li>
            <li><a href="#"
                    data-toggle="search"
                    class="nav-link nav-link-lg d-sm-none"><i class="fas fa-search"></i></a></li>
        </ul>
        <div class="search-element">
            <input class="form-control"
                type="search"
                placeholder="Search"
                aria-label="Search"
                data-width="250">
            <button class="btn"
                type="submit"><i class="fas fa-search"></i></button>
            <div class="search-backdrop"></div>
            <div class="search-result">
                <div class="search-header"> {{ __('Histories') }} </div>
                <div class="search-item">
                    <a href="#"> {{ __('How to hack NASA using CSS') }} </a>
                    <a href="#"
                        class="search-close"><i class="fas fa-times"></i></a>
                </div>
                <div class="search-item">
                    <a href="#"> {{ __('Kodinger.com') }} </a>
                    <a href="#"
                        class="search-close"><i class="fas fa-times"></i></a>
                </div>
                <div class="search-item">
                    <a href="#"> {{ __('#Stisla') }} </a>
                    <a href="#"
                        class="search-close"><i class="fas fa-times"></i></a>
                </div>
                <div class="search-header"> {{ __('Result') }} </div>
                <div class="search-item">
                    <a href="#">
                        <img class="mr-3 rounded"
                            width="30"
                            src="{{ asset('img/products/product-3-50.png') }}"
                            alt="product"> {{ __('oPhone S9 Limited Edition') }} </a>
                </div>
                <div class="search-item">
                    <a href="#">
                        <img class="mr-3 rounded"
                            width="30"
                            src="{{ asset('img/products/product-2-50.png') }}"
                            alt="product"> {{ __('Drone X2 New Gen-7') }} </a>
                </div>
                <div class="search-item">
                    <a href="#">
                        <img class="mr-3 rounded"
                            width="30"
                            src="{{ asset('img/products/product-1-50.png') }}"
                            alt="product"> {{ __('Headphone Blitz') }} </a>
                </div>
                <div class="search-header"> {{ __('Projects') }} </div>
                <div class="search-item">
                    <a href="#">
                        <div class="search-icon bg-danger mr-3 text-white">
                            <i class="fas fa-code"></i>
                        </div> {{ __('Stisla Admin Template') }} </a>
                </div>
                <div class="search-item">
                    <a href="#">
                        <div class="search-icon bg-primary mr-3 text-white">
                            <i class="fas fa-laptop"></i>
                        </div> {{ __('Create a new Homepage Design') }} </a>
                </div>
            </div>
        </div>
    </form>
    <ul class="navbar-nav navbar-right">
        <li class="dropdown dropdown-list-toggle"><a href="#"
                data-toggle="dropdown"
                class="nav-link nav-link-lg message-toggle beep"><i class="far fa-envelope"></i></a>
            <div class="dropdown-menu dropdown-list dropdown-menu-right">
                <div class="dropdown-header"> {{ __('Messages') }} <div class="float-right">
                        <a href="#"> {{ __('Mark All As Read') }} </a>
                    </div>
                </div>
                <div class="dropdown-list-content dropdown-list-message">
                    <a href="#"
                        class="dropdown-item dropdown-item-unread">
                        <div class="dropdown-item-avatar">
                            <img alt="image"
                                src="{{ asset('img/avatar/avatar-1.png') }}"
                                class="rounded-circle">
                            <div class="is-online"></div>
                        </div>
                        <div class="dropdown-item-desc">
                            <b> {{ __('Kusnaedi') }} </b>
                            <p> {{ __('Hello, Bro!') }} </p>
                            <div class="time"> {{ __('10 Hours Ago') }} </div>
                        </div>
                    </a>
                    <a href="#"
                        class="dropdown-item dropdown-item-unread">
                        <div class="dropdown-item-avatar">
                            <img alt="image"
                                src="{{ asset('img/avatar/avatar-2.png') }}"
                                class="rounded-circle">
                        </div>
                        <div class="dropdown-item-desc">
                            <b> {{ __('Dedik Sugiharto') }} </b>
                            <p> {{ __('Lorem ipsum dolor sit amet, consectetur adipisicing elit') }} </p>
                            <div class="time"> {{ __('12 Hours Ago') }} </div>
                        </div>
                    </a>
                    <a href="#"
                        class="dropdown-item dropdown-item-unread">
                        <div class="dropdown-item-avatar">
                            <img alt="image"
                                src="{{ asset('img/avatar/avatar-3.png') }}"
                                class="rounded-circle">
                            <div class="is-online"></div>
                        </div>
                        <div class="dropdown-item-desc">
                            <b> {{ __('Agung Ardiansyah') }} </b>
                            <p> {{ __('Sunt in culpa qui officia deserunt mollit anim id est laborum.') }} </p>
                            <div class="time"> {{ __('12 Hours Ago') }} </div>
                        </div>
                    </a>
                    <a href="#"
                        class="dropdown-item">
                        <div class="dropdown-item-avatar">
                            <img alt="image"
                                src="{{ asset('img/avatar/avatar-4.png') }}"
                                class="rounded-circle">
                        </div>
                        <div class="dropdown-item-desc">
                            <b> {{ __('Ardian Rahardiansyah') }} </b>
                            <p> {{ __('Duis aute irure dolor in reprehenderit in voluptate velit ess') }} </p>
                            <div class="time"> {{ __('16 Hours Ago') }} </div>
                        </div>
                    </a>
                    <a href="#"
                        class="dropdown-item">
                        <div class="dropdown-item-avatar">
                            <img alt="image"
                                src="{{ asset('img/avatar/avatar-5.png') }}"
                                class="rounded-circle">
                        </div>
                        <div class="dropdown-item-desc">
                            <b> {{ __('Alfa Zulkarnain') }} </b>
                            <p> {{ __('Exercitation ullamco laboris nisi ut aliquip ex ea commodo') }} </p>
                            <div class="time"> {{ __('Yesterday') }} </div>
                        </div>
                    </a>
                </div>
                <div class="dropdown-footer text-center">
                    <a href="#"> {{ __('View All') }} <i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
        </li>
        <li class="dropdown dropdown-list-toggle"><a href="#"
                data-toggle="dropdown"
                class="nav-link notification-toggle nav-link-lg beep"><i class="far fa-bell"></i></a>
            <div class="dropdown-menu dropdown-list dropdown-menu-right">
                <div class="dropdown-header"> {{ __('Notifications') }} <div class="float-right">
                        <a href="#"> {{ __('Mark All As Read') }} </a>
                    </div>
                </div>
                <div class="dropdown-list-content dropdown-list-icons">
                    <a href="#"
                        class="dropdown-item dropdown-item-unread">
                        <div class="dropdown-item-icon bg-primary text-white">
                            <i class="fas fa-code"></i>
                        </div>
                        <div class="dropdown-item-desc"> {{ __('Template update is available now!') }} <div class="time text-primary"> {{ __('2 Min Ago') }} </div>
                        </div>
                    </a>
                    <a href="#"
                        class="dropdown-item">
                        <div class="dropdown-item-icon bg-info text-white">
                            <i class="far fa-user"></i>
                        </div>
                        <div class="dropdown-item-desc">
                            <b> {{ __('You') }} </b> {{ __('and') }} <b> {{ __('Dedik Sugiharto') }} </b> {{ __('are now friends') }} <div class="time"> {{ __('10 Hours Ago') }} </div>
                        </div>
                    </a>
                    <a href="#"
                        class="dropdown-item">
                        <div class="dropdown-item-icon bg-success text-white">
                            <i class="fas fa-check"></i>
                        </div>
                        <div class="dropdown-item-desc">
                            <b> {{ __('Kusnaedi') }} </b> {{ __('has moved task') }} <b> {{ __('Fix bug header') }} </b> {{ __('to') }} <b> {{ __('Done') }} </b>
                            <div class="time"> {{ __('12 Hours Ago') }} </div>
                        </div>
                    </a>
                    <a href="#"
                        class="dropdown-item">
                        <div class="dropdown-item-icon bg-danger text-white">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="dropdown-item-desc"> {{ __('Low disk space. Let\'s clean it!') }} <div class="time"> {{ __('17 Hours Ago') }} </div>
                        </div>
                    </a>
                    <a href="#"
                        class="dropdown-item">
                        <div class="dropdown-item-icon bg-info text-white">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="dropdown-item-desc"> {{ __('Welcome to Stisla template!') }} <div class="time"> {{ __('Yesterday') }} </div>
                        </div>
                    </a>
                </div>
                <div class="dropdown-footer text-center">
                    <a href="#"> {{ __('View All') }} <i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
        </li>
        <li class="dropdown"><a href="#"
                data-toggle="dropdown"
                class="nav-link dropdown-toggle nav-link-lg nav-link-user">
                <img alt="image"
                    src="{{ asset('img/avatar/avatar-1.png') }}"
                    class="rounded-circle mr-1">
                <div class="d-sm-none d-lg-inline-block"> {{ __('Hi, Ujang Maman') }} </div>
            </a>
            <div class="dropdown-menu dropdown-menu-right">
                <div class="dropdown-title"> {{ __('Logged in 5 min ago') }} </div>
                <a href="features-profile.html"
                    class="dropdown-item has-icon">
                    <i class="far fa-user"></i> {{ __('Profile') }} </a>
                <a href="features-activities.html"
                    class="dropdown-item has-icon">
                    <i class="fas fa-bolt"></i> {{ __('Activities') }} </a>
                <a href="features-settings.html"
                    class="dropdown-item has-icon">
                    <i class="fas fa-cog"></i> {{ __('Settings') }} </a>
                <div class="dropdown-divider"></div>
                <a href="#"
                    class="dropdown-item has-icon text-danger">
                    <i class="fas fa-sign-out-alt"></i> {{ __('Logout') }} </a>
            </div>
        </li>
    </ul>
</nav>
