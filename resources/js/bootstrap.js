import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Laravel Echo loaded in echo.js (Reverb on port 6001, per 08-API-SPEC-PHASE1.md Section 7)
import './echo';
