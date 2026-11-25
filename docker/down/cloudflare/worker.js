// fahares-failover: the maintenance page for when the whole host gives no answer (backlog item 136,
// step 14.406). The logic is in failover.js; see README.md for setup.
import page from '../index.html';
import { handleFetch, runCheck } from './failover.js';

export default {
    async fetch(request, env) {
        return handleFetch(request, env, page);
    },

    async scheduled(event, env, ctx) {
        ctx.waitUntil(
            runCheck(env).then(
                (result) => console.log(`failover check: ${result}`),
                (error) => console.error(`failover check failed: ${error.message}`),
            ),
        );
    },
};
