import { __ } from '@wordpress/i18n';

import Icon from '../icons';
import { Logo } from '../components/Header';
import { config } from '../api';

const About = () => {
	const author = config.author || {};
	return (
		<div className="sa-page">
			<section className="sa-author">
				<div className="sa-author__avatar" aria-hidden="true">
					KB
				</div>
				<div className="sa-author__body">
					<span className="sa-eyebrow">{ __( 'Designed & developed by', 'pack-vault' ) }</span>
					<h1>{ author.name }</h1>
					<p>
						{ __(
							'WordPress developer building fast, reliable tools for site owners. Packvault was born from a daily need: one dependable file that holds an entire website.',
							'pack-vault'
						) }
					</p>
					<div className="sa-row">
						<a className="sa-btn sa-btn--light" href={ author.url } target="_blank" rel="noopener noreferrer">
							<Icon name="globe" size={ 18 } />
							khalidbinahsan.com
						</a>
						<a className="sa-btn sa-btn--glass" href={ `mailto:${ author.email }` }>
							<Icon name="mail" size={ 18 } />
							{ author.email }
						</a>
					</div>
				</div>
			</section>

			<section className="sa-grid sa-grid--3">
				<div className="sa-feature">
					<Logo size={ 40 } />
					<h3>Packvault { config.version }</h3>
					<p>{ __( 'Backup, restore and migration in a single portable .packvault file.', 'pack-vault' ) }</p>
				</div>
				<a className="sa-feature is-link" href={ author.reviews } target="_blank" rel="noopener noreferrer">
					<Icon name="star" size={ 22 } />
					<h3>{ __( 'Enjoying Packvault?', 'pack-vault' ) }</h3>
					<p>{ __( 'A 5-star review on WordPress.org helps more people keep their sites safe.', 'pack-vault' ) }</p>
				</a>
				<a className="sa-feature is-link" href={ `mailto:${ author.email }?subject=Packvault%20support` }>
					<Icon name="mail" size={ 22 } />
					<h3>{ __( 'Need help?', 'pack-vault' ) }</h3>
					<p>{ __( 'Found a bug or have an idea? Email the developer directly.', 'pack-vault' ) }</p>
				</a>
			</section>

			<section className="sa-card">
				<div className="sa-card__head">
					<h2>{ __( 'How Packvault works', 'pack-vault' ) }</h2>
				</div>
				<ol className="sa-how">
					<li>
						<strong>{ __( 'One file', 'pack-vault' ) }</strong>
						{ __( 'Database, media, plugins and themes are streamed into a single .packvault archive.', 'pack-vault' ) }
					</li>
					<li>
						<strong>{ __( 'No limits', 'pack-vault' ) }</strong>
						{ __( 'Work runs in short, resumable steps, so huge sites never hit PHP time or memory limits.', 'pack-vault' ) }
					</li>
					<li>
						<strong>{ __( 'Move anywhere', 'pack-vault' ) }</strong>
						{ __( 'Upload the file on any WordPress site with Packvault and restore — URLs and paths are updated for you.', 'pack-vault' ) }
					</li>
				</ol>
			</section>
		</div>
	);
};

export default About;
