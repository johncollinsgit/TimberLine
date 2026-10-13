const mobileNavigation = document.querySelector('[data-evergrove-mobile-nav]');
if (mobileNavigation) {
    mobileNavigation.addEventListener('click', event => {
        if (event.target.closest('a')) mobileNavigation.open = false;
    });
    document.addEventListener('click', event => {
        if (!mobileNavigation.contains(event.target)) mobileNavigation.open = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && mobileNavigation.open) {
            mobileNavigation.open = false;
            mobileNavigation.querySelector('summary').focus();
        }
    });
    matchMedia('(max-width: 980px)').addEventListener('change', event => {
        if (!event.matches) mobileNavigation.open = false;
    });
}
