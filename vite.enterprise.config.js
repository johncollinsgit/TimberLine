import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import laravel from "laravel-vite-plugin";

// An independent deploy artifact: never overwrite the retail application's build.
export default defineConfig({
    plugins: [
        react(),
        laravel({
            input: ["resources/js/enterprise/workspace.tsx"],
            publicDirectory: "apps/enterprise/public",
            hotFile: "apps/enterprise/public/hot",
            refresh: false,
        }),
    ],
    build: {
        manifest: "manifest.json",
        outDir: "apps/enterprise/public/build",
        emptyOutDir: true,
    },
});
