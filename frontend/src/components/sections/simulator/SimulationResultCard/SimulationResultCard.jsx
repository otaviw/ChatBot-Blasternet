import './SimulationResultCard.css';
import Card from '@/components/ui/Card/Card.jsx';

function SimulationResultCard({ items }) {
  return (
    <Card className="mt-6">
      <h2 className="text-base font-semibold text-[#0f172a] mb-3">Resultado</h2>
      <ul className="space-y-2 text-sm text-[#334155]">
        {items.map((item) => (
          <li key={item.label} className="flex flex-wrap gap-1">
            <span className="font-medium text-[#0f172a]">{item.label}:</span>
            <span>{item.value}</span>
          </li>
        ))}
      </ul>
    </Card>
  );
}

export default SimulationResultCard;

