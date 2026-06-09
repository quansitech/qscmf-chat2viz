const path = require('path');
const webpack = require('webpack');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
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
  resolve: {
    alias: {
      // For v13 builds, redirect all adapter imports to the smarty adapter.
      // Use absolute paths as alias keys so that webpack matches the resolved
      // module path regardless of whether the import is './adapters' or
      // '../adapters' from any subdirectory of the Chat2viz source tree.
      [path.resolve(__dirname, '../asset/inertia/Chat2viz/adapters')]:
        path.resolve(__dirname, '../asset/inertia/Chat2viz/adapters/smarty.ts'),
      // react-grid-layout no longer bundles react-resizable.css; redirect to the actual package
      'react-grid-layout/css/react-resizable.css': path.resolve(__dirname, 'node_modules/react-resizable/css/styles.css'),
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
          loader: 'ts-loader',
          options: { transpileOnly: true },
        },
        exclude: /node_modules/,
      },
      {
        test: /\.css$/,
        use: [MiniCssExtractPlugin.loader, 'css-loader'],
      },
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: '[name].css',
    }),
    new webpack.DefinePlugin({
      'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
    }),
  ],
  // G2 is loaded via CDN (<script src="unpkg.com/@antv/g2@5/...">) — do not bundle it.
  externals: {
    '@antv/g2': 'G2',
  },
};
