// Webpack config MUST be CommonJS — webpack-cli loads it via require().
// Do not convert to ESM: the .ts loader chain is wired against CJS `require`.
// (If the [80001] CommonJS hint shows up in your editor, that's why.)
const path = require('path');
const webpack = require('webpack');
const TerserPlugin = require('terser-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

// Use a function so we can branch on --mode (passed as argv.mode by webpack-cli).
// Cheap config-switching beats building two configs and merging them.
// Use a function so we can branch on --mode (passed as argv.mode by webpack-cli).
// `_env` is the user-defined --env.foo=bar map; not used here but kept in the
// signature so it matches webpack-cli docs and stays greppable.
module.exports = (_env, argv) => {
  const isProd = Boolean(argv) && argv.mode === 'production';

  return {
    entry: {
      'dashboard-list': './entries/dashboard-list.tsx',
      'dashboard-edit': './entries/dashboard-edit.tsx',
      'dashboard-view': './entries/dashboard-view.tsx',
    },
    output: {
      path: path.resolve(__dirname, '../asset/chat2viz-dashboard'),
      filename: '[name].js',
      clean: true,
    },
    // Source-map strategy
    // - prod: hidden-source-map — emits .map files (upload to error monitoring)
    //   but does NOT add a //# sourceMappingURL comment in the bundle.
    //   Saves ~30–50% build CPU vs full 'source-map'.
    // - dev: cheap-module-source-map — line-level maps in browser DevTools,
    //   fast rebuilds. (No column maps; cheap is enough for local dev.)
    devtool: isProd ? 'hidden-source-map' : 'cheap-module-source-map',

    // Persistent filesystem cache. Incremental builds ~60% faster.
    // The .webpack-cache directory is gitignored.
    cache: {
      type: 'filesystem',
      cacheDirectory: path.resolve(__dirname, '.webpack-cache'),
      buildDependencies: {
        config: [__filename],
      },
    },

    resolve: {
      alias: {
        // For v13 builds, redirect all adapter imports to the smarty adapter.
        // Use absolute paths as alias keys so that webpack matches the resolved
        // module path regardless of whether the import is './adapters' or
        // '../adapters' from any subdirectory of the Chat2viz source tree.
        [path.resolve(__dirname, '../asset/inertia/Chat2viz/adapters')]:
          path.resolve(__dirname, '../asset/inertia/Chat2viz/adapters/smarty.ts'),
        // react-grid-layout no longer bundles react-resizable.css; redirect to the actual package
        'react-grid-layout/css/react-resizable.css':
          path.resolve(__dirname, 'node_modules/react-resizable/css/styles.css'),
      },
      extensions: ['.ts', '.tsx', '.js', '.jsx'],
      // Source files in ../asset/inertia/ need to resolve modules from frontend/node_modules
      modules: [path.resolve(__dirname, 'node_modules'), 'node_modules'],
    },
    module: {
      rules: [
        {
          test: /\.tsx?$/,
          use: {
            loader: 'swc-loader',
            options: {
              // swc does fast transpilation only (no type-checking). Type
              // safety stays with `tsc --noEmit` (the typecheck npm script),
              // preserving the old ts-loader transpileOnly:true contract —
              // build speed up, type errors still caught in CI/lint.
              //
              // Config mirrors tsconfig.json compilerOptions:
              //   target ES2020, jsx react-jsx (automatic runtime),
              //   module ESNext. swc handles these via env/jsc, not tsconfig.
              jsc: {
                parser: {
                  syntax: 'typescript',
                  tsx: true,
                  // Allow decorators (antd/popover patterns). No emit_decorator
                  // metadata needed (we don't use reflect-metadata).
                  decorators: false,
                },
                transform: {
                  react: {
                    runtime: 'automatic',
                  },
                },
                target: 'es2020',
                // Keep CommonJS interop working (esModuleInterop:true).
                externalHelpers: false,
              },
            },
          },
          exclude: /node_modules/,
        },
        {
          test: /\.css$/,
          use: [MiniCssExtractPlugin.loader, 'css-loader'],
        },
      ],
    },
    optimization: {
      minimize: isProd,
      minimizer: [
        // Explicit Terser config. parallel:true (default in webpack 5, but we
        // set it explicitly so it's reviewable). drop_console in prod strips
        // console.* calls at minification time, no source-code changes needed.
        new TerserPlugin({
          parallel: true,
          extractComments: false,
          terserOptions: {
            compress: {
              drop_console: isProd,
              passes: 2,
            },
            format: {
              comments: false,
            },
          },
        }),
      ],
      // NOTE: splitChunks / runtimeChunk deliberately omitted.
      // view/edit HTML templates hardcode <script src=".../dashboard-<entry>.js">,
      // so any extracted chunk (vendors.js, runtime.js) would not be loaded
      // by the page. Re-introduce splitChunks only after switching to
      // HtmlWebpackPlugin (or splitting entry filename strategy).
    },
    plugins: [
      new MiniCssExtractPlugin({
        filename: '[name].css',
      }),
      new webpack.DefinePlugin({
        // react-draggable (used by react-grid-layout) references `process` directly
        // at runtime (e.g. in handleDragStart).  Defining the full `process.env`
        // object ensures all access patterns — process.env.NODE_ENV, process.env,
        // and indirect references — resolve correctly in the browser.
        'process.env': {
          NODE_ENV: JSON.stringify(process.env.NODE_ENV || (isProd ? 'production' : 'development')),
        },
      }),
    ],
    // G2 is loaded via CDN (<script src="unpkg.com/@antv/g2@5/...">) — do not bundle it.
    externals: {
      '@antv/g2': 'G2',
    },

    // Warn (do not error) when bundles exceed 600 KB. Easier to catch regressions
    // than reading terser output alone.
    performance: {
      hints: isProd ? 'warning' : false,
      maxAssetSize: 600 * 1024,
      maxEntrypointSize: 600 * 1024,
    },

    // Quiet stats: only print assets + warnings/errors. Modules/chunks noise is
    // rarely useful for this project.
    stats: {
      assets: true,
      modules: false,
      chunks: false,
      children: false,
      entrypoints: false,
      colors: true,
    },
  };
};
