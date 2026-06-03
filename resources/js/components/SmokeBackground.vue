<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

const canvasRef = ref<HTMLCanvasElement | null>(null);
let rafId = 0;
let resizeHandler: () => void;

const DENSITY = 11.0;

const VERT = `attribute vec2 a_pos;void main(){gl_Position=vec4(a_pos,0.0,1.0);}`;

const FRAG = `
precision highp float;
uniform vec2 u_res;
uniform float u_time;
uniform float u_density;

float hash(vec2 p){p=fract(p*vec2(234.34,435.345));p+=dot(p,p+34.23);return fract(p.x*p.y);}
float noise(vec2 p){
  vec2 i=floor(p);vec2 f=fract(p)*fract(p)*(3.0-2.0*fract(p));
  return mix(mix(hash(i),hash(i+vec2(1,0)),f.x),mix(hash(i+vec2(0,1)),hash(i+vec2(1,1)),f.x),f.y);
}
float fbm(vec2 p){
  float v=0.0,a=0.5;
  for(int i=0;i<6;i++){v+=a*noise(p);p=p*2.0+100.0;a*=0.5;}
  return v;
}
void main(){
  vec2 c=gl_FragCoord.xy/u_res-0.5;
  float t=u_time*0.18;
  vec2 d=c;
  vec2 d25=d*2.5;
  vec2 d20=d*2.0;
  vec2 q=vec2(fbm(d25+vec2(0.0,t)),fbm(d25+vec2(0.0,t+1.3)));
  vec2 r=vec2(fbm(d20+1.7*q+vec2(0.0,9.2)+0.15*t),fbm(d20+1.7*q+vec2(0.0,2.8)+0.126*t));
  float f=fbm(d20+r);
  f=smoothstep(0.5-u_density*0.04, 0.7+(1.0-u_density*0.08), f);

  vec3 colA = mix(vec3(0.1,0.02,0.0), mix(vec3(0.8,0.3,0.05), vec3(1.0,0.85,0.4), f), f);
  vec3 colB = mix(vec3(0.08,0.0,0.12), mix(vec3(0.5,0.1,0.7), vec3(0.9,0.6,1.0), f), f);

  float diagBlend = smoothstep(-0.1, 0.1, c.x*0.5 + c.y);
  vec3 col = mix(colA, colB, diagBlend);
  col *= 1.0-smoothstep(0.4,1.2,length(c)*1.5);
  gl_FragColor=vec4(col,1.0);
}`;

onMounted(() => {
    const canvas = canvasRef.value;

    if (!canvas) {
        return;
    }

    const gl = canvas.getContext('webgl');

    if (!gl) {
        return;
    }

    function resize() {
        if (!canvas || !gl) {
            return;
        }

        canvas.width = Math.ceil(window.innerWidth / 6);
        canvas.height = Math.ceil(window.innerHeight / 6);
        canvas.style.width = window.innerWidth + 'px';
        canvas.style.height = window.innerHeight + 'px';
        gl.viewport(0, 0, canvas.width, canvas.height);
    }
    resize();

    function compile(type: number, src: string) {
        const s = gl!.createShader(type)!;
        gl!.shaderSource(s, src);
        gl!.compileShader(s);

        return s;
    }

    const prog = gl.createProgram()!;
    gl.attachShader(prog, compile(gl.VERTEX_SHADER, VERT));
    gl.attachShader(prog, compile(gl.FRAGMENT_SHADER, FRAG));
    gl.linkProgram(prog);
    gl.useProgram(prog);

    const buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(
        gl.ARRAY_BUFFER,
        new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]),
        gl.STATIC_DRAW,
    );
    const loc = gl.getAttribLocation(prog, 'a_pos');
    gl.enableVertexAttribArray(loc);
    gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);

    const uRes = gl.getUniformLocation(prog, 'u_res');
    const uTime = gl.getUniformLocation(prog, 'u_time');
    const uDensity = gl.getUniformLocation(prog, 'u_density');

    const start = performance.now();

    function loop() {
        if (!gl || !canvas) {
            return;
        }

        const t = (performance.now() - start) / 1000;
        gl.uniform2f(uRes, canvas.width, canvas.height);
        gl.uniform1f(uTime, t);
        gl.uniform1f(uDensity, DENSITY);
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
        rafId = requestAnimationFrame(loop);
    }

    loop();
    resizeHandler = resize;
    window.addEventListener('resize', resizeHandler);
});

onUnmounted(() => {
    cancelAnimationFrame(rafId);
    window.removeEventListener('resize', resizeHandler);
});
</script>

<template>
    <canvas ref="canvasRef" class="bg-anim-smoke" />
</template>
