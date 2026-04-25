module.exports = {
	extends: [
		'@nextcloud',
	],
	parser: 'vue-eslint-parser',
	parserOptions: {
		parser: '@typescript-eslint/parser',
		ecmaVersion: 2022,
		sourceType: 'module',
	},
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		// Vue 3 supports multi-root templates
		'vue/no-multiple-template-root': 'off',
		// False positive on `for (...; d.setDate(...))` style loops; ESLint can't see method-call mutations
		'no-unmodified-loop-condition': 'off',
	},
}
