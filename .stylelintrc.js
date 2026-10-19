module.exports = {
	'extends': '@wordpress/stylelint-config',
	'rules': {
		'at-rule-no-unknown': [
			true,
			{
				'ignoreAtRules': [
					'custom-media',
					'source:',
					'theme',
				],
			},
		],
		'font-family-no-missing-generic-family-keyword': null,
		'function-url-quotes': null,
	},
	'ignoreFiles': [
		'build/**',
		'node_modules/**',
		'vendor/**',
		'src/shared/lib/languages/styles.css'
	]
};
