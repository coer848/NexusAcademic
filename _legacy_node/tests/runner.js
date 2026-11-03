import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const testFiles = [
  'auth.test.js',
  'attendance.test.js',
  'grades.test.js',
  'security.test.js',
  'ui.test.js'
];

console.log('===============================================================');
console.log('🧪 EJECUTANDO SUITE COMPLETA DE PRUEBAS AUTOMATIZADAS (NEXUS)');
console.log('===============================================================\n');

let failedSuites = 0;

for (const file of testFiles) {
  const filePath = path.join(__dirname, file);
  console.log(`▶ Ejecutando suite: ${file}...`);

  await new Promise((resolve) => {
    const child = spawn(process.execPath, ['--test', filePath], {
      stdio: 'inherit',
      cwd: path.join(__dirname, '..')
    });

    child.on('close', (code) => {
      if (code !== 0) {
        failedSuites++;
        console.error(`❌ Suite ${file} falló con código ${code}\n`);
      } else {
        console.log(`✅ Suite ${file} pasó exitosamente.\n`);
      }
      resolve();
    });
  });
}

console.log('===============================================================');
if (failedSuites === 0) {
  console.log('🎉 TODAS LAS PRUEBAS PASARON AL 100% (0 FALLOS)');
  console.log('===============================================================');
  process.exit(0);
} else {
  console.error(`💥 ${failedSuites} SUITES DE PRUEBAS FALLARON.`);
  console.log('===============================================================');
  process.exit(1);
}
