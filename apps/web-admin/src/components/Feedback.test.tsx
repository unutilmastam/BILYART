import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../lib/api';
import { ErrorBanner } from './Feedback';

describe('ErrorBanner', () => {
  it('shows the request id for server errors so support can find the log line', () => {
    render(<ErrorBanner error={new ApiError(500, 'SERVER_ERROR', "Nimadir xato ketdi. Qayta urinib ko'ring.", {}, 'req-123')} />);
    expect(screen.getByRole('alert')).toHaveTextContent("So'rov ID: req-123");
  });

  it('hides it for ordinary business errors', () => {
    render(<ErrorBanner error={new ApiError(422, 'LIMIT_REACHED', 'Litsenziya limitiga yetdingiz (2).', {}, 'req-456')} />);
    expect(screen.getByRole('alert')).not.toHaveTextContent('req-456');
  });
});
