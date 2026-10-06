export default {
    async fetch(request) {
        const canonical = new URL(request.url);
        canonical.protocol = 'https:';
        canonical.hostname = 'theeverbranch.com';

        return Response.redirect(canonical.toString(), 301);
    },
};
