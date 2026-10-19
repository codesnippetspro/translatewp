import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const languagesDirectory = path.join(
    path.dirname( fileURLToPath( import.meta.url ) ),
    '..',
    'src',
    'shared',
    'lib',
    'languages',
);
const css = fs.readFileSync( path.join( languagesDirectory, 'styles.css' ), 'utf8' );
const positions = {};

for ( const match of css.matchAll( /\.lang-icon-([a-z0-9-]+)\s*\{[^}]*background-position:\s*(-?\d+)px\s*(-?\d+)px/g ) ) {
    positions[match[1]] = {
        x: Number.parseInt( match[2], 10 ),
        y: Number.parseInt( match[3], 10 ),
    };
}

const content = `export const FLAG_SPRITE_SHEET_WIDTH = 600;
export const FLAG_SPRITE_SHEET_HEIGHT = 375;
export const FLAG_SPRITE_CELL_WIDTH = 25;
export const FLAG_SPRITE_CELL_HEIGHT = 15;

export type FlagSpritePosition = { x: number; y: number };

export const flagSpritePositions: Record<string, FlagSpritePosition> = ${ JSON.stringify( positions, null, 4 ) };
`;

fs.writeFileSync( path.join( languagesDirectory, 'flagSpritePositions.ts' ), content );

console.log( `Generated ${ Object.keys( positions ).length } flag sprite positions.` );
