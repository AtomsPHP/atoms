import {bootPHP as bootEmbed} from './embed-loader.js';
export {composeBootCode, writeGuestFile, mkdirp} from './guest-files.js';

export function bootPHP({factory, wasm}, doors) {
    return bootEmbed(factory, wasm, {...doors, print: line => console.log(line), printErr: line => console.error(line)});
}

export function guestMemoryBytes(php) {
    return php.memoryBytes;
}
