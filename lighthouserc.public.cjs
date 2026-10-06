module.exports = {
    ci: {
        collect: {
            startServerCommand: "sh scripts/qa/serve-public-visual.sh",
            startServerReadyPattern: "Server running on",
            startServerReadyTimeout: 60_000,
            url: ["http://127.0.0.1:4177/platform/promo", "http://127.0.0.1:4177/platform/plans", "http://127.0.0.1:4177/explore/modules"],
            numberOfRuns: 2,
            settings: { preset: "desktop" },
        },
        assert: {
            assertions: {
                "categories:performance": ["warn", { minScore: 0.8 }],
                "categories:accessibility": ["error", { minScore: 0.95 }],
                "categories:best-practices": ["warn", { minScore: 0.9 }],
                "categories:seo": ["warn", { minScore: 0.9 }],
                "cumulative-layout-shift": ["error", { maxNumericValue: 0.1 }],
                "largest-contentful-paint": ["warn", { maxNumericValue: 4000 }],
            },
        },
        upload: { target: "temporary-public-storage" },
    },
};
