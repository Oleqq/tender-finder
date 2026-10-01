import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Axios reads Laravel's current XSRF-TOKEN cookie for each same-origin request.
// A token copied once from the HTML becomes stale when login rotates the session.
