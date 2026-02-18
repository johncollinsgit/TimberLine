// resources/js/bootstrap.js

// If you use axios anywhere:
import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// If you later want Echo/Pusher, you can add it here.
