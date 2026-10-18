"""Rebuild original demo diagrams; development-only dependency: matplotlib.

Run from any directory. The Drupal demo consumes the PNGs; SVGs retain editable
vector masters. Surface tension is S, consistently with the teaching text.
"""
from pathlib import Path

import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
from matplotlib.patches import Circle, PathPatch, Rectangle
from matplotlib.path import Path as DrawPath

OUTPUT = Path(__file__).resolve().parent
INK = '#193c43'
BLUE = '#d9edf7'
EDGE = '#286c81'
GREY = '#667984'
plt.rcParams.update({'font.family': 'DejaVu Sans', 'font.size': 16,
                     'svg.fonttype': 'none', 'mathtext.fontset': 'dejavusans'})


def canvas():
    fig, ax = plt.subplots(figsize=(12, 6.4), dpi=150)
    fig.patch.set_facecolor('white')
    ax.set(xlim=(0, 12), ylim=(0, 6.4), aspect='equal')
    ax.axis('off')
    fig.subplots_adjust(left=.015, right=.985, bottom=.02, top=.98)
    return fig, ax


def label(ax, x, y, text, **kwargs):
    ax.text(x, y, text, color=INK, **kwargs)


def arrow(ax, a, b, color=EDGE, both=False):
    ax.annotate('', xy=b, xytext=a,
                arrowprops={'arrowstyle': '<->' if both else '->',
                            'color': color, 'lw': 2.1, 'mutation_scale': 17})


def save(fig, name):
    fig.savefig(OUTPUT / f'{name}.png', facecolor='white', dpi=150)
    fig.savefig(OUTPUT / f'{name}.svg', facecolor='white')
    plt.close(fig)


fig, ax = canvas()
label(ax, .55, 5.85, 'Soap film: two liquid–air interfaces', weight='bold', size=21)
ax.add_patch(Rectangle((1.05, 1.35), 5.65, 3.8, facecolor=BLUE,
                       edgecolor='none'))
ax.plot([6.8, 1.05, 1.05, 6.8], [5.15, 5.15, 1.35, 1.35], color=GREY, lw=5)
ax.plot([6.5, 6.5], [1.15, 5.35], color='#b39261', lw=8)
for y in (2.05, 3.25, 4.45):
    arrow(ax, (6.15, y), (4.35, y))
label(ax, 2.05, 3.75, 'Film pulls inward')
arrow(ax, (7, 1.35), (7, 5.15), both=True)
label(ax, 7.18, 4.65, r'$L$', size=22)
arrow(ax, (6.85, 3.15), (9.2, 3.15), color='#9c552b')
label(ax, 7.55, 3.6, r'$F_{\mathrm{external}}=2SL$', size=21)
label(ax, 1.25, .5, r'Work: $F\,dx=S\,dA$, with $dA=2L\,dx$', size=21)
save(fig, 'soap-film-force')

fig, ax = canvas()
for x, title, count, equation, double in [
    (3, 'Liquid drop', 'One interface', r'$p_{\mathrm{in}}-p_{\mathrm{out}}=2S/R$', False),
    (9, 'Soap bubble', 'Two interfaces', r'$p_{\mathrm{in}}-p_{\mathrm{out}}\simeq4S/R$', True),
]:
    label(ax, x, 5.85, title, ha='center', weight='bold', size=22)
    ax.add_patch(Circle((x, 3.45), 1.5, edgecolor=EDGE, lw=3,
                        facecolor='white' if double else BLUE))
    if double:
        ax.add_patch(Circle((x, 3.45), 1.4, edgecolor=EDGE, lw=1.5, facecolor='none'))
    arrow(ax, (x, 3.45), (x+1.06, 4.51))
    label(ax, x+.15, 4.15, r'$R$', size=22)
    label(ax, x, 1.55, count, ha='center')
    label(ax, x, .95, equation, ha='center', size=21)
label(ax, 9, .35, 'Thin-film limit', ha='center', size=13)
save(fig, 'drop-bubble-pressure')

fig, ax = canvas()
label(ax, .6, 5.9, 'Capillary rise: wetting liquid', weight='bold', size=21)
# A concave meniscus meets the tube walls above the much wider reservoir.
ax.add_patch(Rectangle((.55, .65), 10.9, 1.35, facecolor=BLUE, edgecolor='none'))
fluid = DrawPath([(3.6, 2), (3.6, 4.8), (4.45, 4.1), (5.3, 4.8),
                  (5.3, 2), (3.6, 2)],
                 [DrawPath.MOVETO, DrawPath.LINETO, DrawPath.CURVE3,
                  DrawPath.CURVE3, DrawPath.LINETO, DrawPath.CLOSEPOLY])
ax.add_patch(PathPatch(fluid, facecolor=BLUE, edgecolor='none'))
ax.plot([.55, .55, 11.45, 11.45], [2.4, .65, .65, 2.4], color=GREY, lw=3)
ax.plot([3.6, 3.6], [.85, 5.5], color=GREY, lw=3)
ax.plot([5.3, 5.3], [.85, 5.5], color=GREY, lw=3)
ax.add_patch(PathPatch(DrawPath([(3.6, 4.8), (4.45, 4.1), (5.3, 4.8)],
                               [DrawPath.MOVETO, DrawPath.CURVE3, DrawPath.CURVE3]),
                        facecolor='none', edgecolor=EDGE, lw=2.5))
ax.plot([.7, 10.6], [2, 2], color=GREY, lw=1.2, ls='--')
arrow(ax, (4.45, 5.15), (5.3, 5.15), both=True)
label(ax, 4.85, 5.35, r'$r$', ha='center', size=20)
arrow(ax, (5.95, 2), (5.95, 4.45), both=True)
label(ax, 6.1, 3.1, r'$h$', size=22)
label(ax, 7.25, 4.65, r'$h=\frac{2S\cos\theta}{\rho g r}$', size=27)
label(ax, 7.25, 3.65, 'Static equilibrium', size=16)
label(ax, 7.25, 3.18, 'Uniform narrow tube', size=16)
label(ax, 7.25, 2.71, 'Wide reservoir', size=16)
label(ax, 6, .12, 'Schematic: meniscus volume neglected in the elementary balance.',
      ha='center', size=13)
save(fig, 'capillary-rise')
