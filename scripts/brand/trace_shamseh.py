# Rebuilds public/images/brand/shamseh.svg from shamseh.png: centre fix, D4 symmetry inside,
# 36-fold symmetry in the braided ring, smoothed colour masks traced with potrace (pip: potracer numpy pillow).
# Usage: python trace_shamseh.py <work-dir> [size]; the SVG is written to <work-dir>/trace/shamseh-sym.svg
import os, sys, numpy as np, potrace, time
from PIL import Image, ImageFilter
S=sys.argv[1]; os.makedirs(f'{S}/trace', exist_ok=True); N=int(sys.argv[2]) if len(sys.argv)>2 else 1800
src=Image.open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '../../public/images/brand/shamseh.png')).convert('RGBA')
W=src.size[0]
# true centre found at +1px per 700px from the image middle
off=1*W/700
pad=Image.new('RGBA',(W+8,W+8),(0,0,0,0)); pad.paste(src,(4,4))
big=pad.resize((N,N),Image.LANCZOS, box=(4+off,4+off,4+W+off,4+W+off))
flat=Image.new('RGB',big.size,(6,65,90)); flat.paste(big,(0,0),big)
flat=flat.filter(ImageFilter.MedianFilter(5))
# the logo's own four flat colours: navy ground, mid teal outline, light teal arabesque, white
pal=np.array([[6,65,90],[45,114,133],[93,193,209],[212,235,240]])
px=np.asarray(flat).astype(np.int32)
idx=np.argmin(((px[:,:,None,:]-pal[None,None,:,:])**2).sum(-1),axis=2)
yy,xx=np.mgrid[:N,:N]; r=np.hypot(xx-N/2+0.5,yy-N/2+0.5)/(N/2)
inner=r<0.795
def sym_inner(m):
    acc=np.zeros_like(m)
    for k in range(4):
        rm=np.rot90(m,k); acc+=rm; acc+=rm.T
    return acc/8
def sym_ring(m):
    im=Image.fromarray(m.astype(np.float32),'F'); acc=np.zeros_like(m)
    for k in range(36):
        acc+=np.asarray(im.rotate(10*k,resample=Image.BICUBIC))
    return acc/36
t=time.time(); probs=[]
for i in range(4):
    m=(idx==i).astype(np.float32)
    s=np.where(inner,sym_inner(m),sym_ring(m))
    s=np.asarray(Image.fromarray(np.clip(s*255,0,255).astype(np.uint8),'L').filter(ImageFilter.GaussianBlur(N/700*1.1))).astype(np.float32)
    probs.append(s)
lab=np.argmax(np.stack(probs),axis=0)
print('symmetrised in %.0fs'%(time.time()-t))
counts=[int((lab==i).sum()) for i in range(4)]; order=sorted(range(4),key=lambda i:-counts[i])
def hexc(i): return '#%02x%02x%02x'%tuple(pal[i])
def path_d(plist):
    out=[]
    for c in plist:
        sp=c.start_point; out.append(f'M{sp.x:.1f} {sp.y:.1f}')
        for g in c.segments:
            if g.is_corner: out.append(f'L{g.c.x:.1f} {g.c.y:.1f}L{g.end_point.x:.1f} {g.end_point.y:.1f}')
            else: out.append(f'C{g.c1.x:.1f} {g.c1.y:.1f} {g.c2.x:.1f} {g.c2.y:.1f} {g.end_point.x:.1f} {g.end_point.y:.1f}')
        out.append('Z')
    return ''.join(out).replace('.0 ',' ').replace('.0L','L').replace('.0C','C').replace('.0Z','Z')
inside=r<0.997
parts=[f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {N} {N}"><title>فهارس</title><defs><clipPath id="c"><circle cx="{N/2}" cy="{N/2}" r="{N/2*0.997:.1f}"/></clipPath></defs><g clip-path="url(#c)">',
       f'<circle cx="{N/2}" cy="{N/2}" r="{N/2}" fill="{hexc(order[0])}"/>']
for i in order[1:]:
    mask=(lab==i)&inside
    if mask.sum()<300: continue
    pl=potrace.Bitmap(~mask).trace(turdsize=int((N/700)**2*4), alphamax=1.1, opticurve=True, opttolerance=0.5)
    parts.append(f'<path fill="{hexc(i)}" fill-rule="evenodd" d="{path_d(pl)}"/>')
parts.append('</g></svg>')
out=''.join(parts); open(f'{S}/trace/shamseh-sym.svg','w').write(out)
print('colours',[hexc(i) for i in order],'bytes',len(out))
